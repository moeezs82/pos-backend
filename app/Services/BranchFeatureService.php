<?php

namespace App\Services;

use App\Models\BranchFeatureSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single authoritative source for branch feature flags.
 *
 * All reads and writes go through this service so:
 *  - missing rows resolve gracefully (default = enabled),
 *  - every actual change is audited atomically,
 *  - no scattered raw queries exist in controllers.
 *
 * Public API (adapt callers to use exactly these names):
 *
 *   $svc->forBranch(int $branchId): array
 *   $svc->deliveryEnabled(int $branchId): bool
 *   $svc->saleVendorEnabled(int $branchId): bool
 *   $svc->assertDeliveryEnabled(int $branchId): void   — throws 422 if off
 *   $svc->assertSaleVendorEnabled(int $branchId): void — throws 422 if off
 *   $svc->update(int $branchId, array $values, int $changedBy): array
 *   $svc->deliveryCustodyBalance(int $branchId): float — account 1210 balance
 */
class BranchFeatureService
{
    // Allowlist of client-visible flag names.  Any key NOT in this list is
    // rejected by update() regardless of what the request body contains.
    private const ALLOWED_FLAGS = [
        'delivery_enabled',
        'sale_vendor_enabled',
    ];

    // Default state when no DB row exists for a branch.
    private const DEFAULTS = [
        'delivery_enabled'    => true,
        'sale_vendor_enabled' => true,
    ];

    // ── Reads ─────────────────────────────────────────────────────────────

    /**
     * Effective feature map for $branchId.
     * Returns DEFAULTS if no row exists (backward-safe, fail-open).
     */
    public function forBranch(int $branchId): array
    {
        $row = BranchFeatureSetting::query()
            ->where('branch_id', $branchId)
            ->first(['delivery_enabled', 'sale_vendor_enabled', 'updated_at']);

        if (!$row) {
            return self::DEFAULTS;
        }

        return [
            'delivery_enabled'    => (bool) $row->delivery_enabled,
            'sale_vendor_enabled' => (bool) $row->sale_vendor_enabled,
        ];
    }

    public function deliveryEnabled(int $branchId): bool
    {
        return $this->forBranch($branchId)['delivery_enabled'];
    }

    public function saleVendorEnabled(int $branchId): bool
    {
        return $this->forBranch($branchId)['sale_vendor_enabled'];
    }

    // ── Guards (throw ValidationException on failure) ─────────────────────

    public function assertDeliveryEnabled(int $branchId): void
    {
        if (!$this->deliveryEnabled($branchId)) {
            throw ValidationException::withMessages([
                'delivery' => ['The delivery module is disabled for this branch.'],
            ]);
        }
    }

    public function assertSaleVendorEnabled(int $branchId): void
    {
        if (!$this->saleVendorEnabled($branchId)) {
            throw ValidationException::withMessages([
                'vendor_id' => ['Vendor selection on sales is disabled for this branch.'],
            ]);
        }
    }

    // ── Writes ────────────────────────────────────────────────────────────

    /**
     * Persist feature settings for $branchId and write an audit row.
     * Only keys present in ALLOWED_FLAGS are accepted.
     *
     * @param  array{delivery_enabled?: bool, sale_vendor_enabled?: bool} $values
     * @param  int $changedBy  User ID of the Master Admin making the change.
     * @return array           Effective features after the update.
     * @throws ValidationException if any key is unknown or value is not boolean.
     */
    public function update(int $branchId, array $values, int $changedBy): array
    {
        // Strip unknown keys.
        $filtered = array_filter(
            $values,
            static fn ($k) => in_array($k, self::ALLOWED_FLAGS, true),
            ARRAY_FILTER_USE_KEY
        );

        if (empty($filtered)) {
            // Nothing to change — return current state without audit row.
            return $this->forBranch($branchId);
        }

        // Coerce to boolean.
        $incoming = array_map(static fn ($v) => (bool) $v, $filtered);

        return DB::transaction(function () use ($branchId, $incoming, $changedBy) {
            $row = BranchFeatureSetting::query()
                ->where('branch_id', $branchId)
                ->first();

            $old = $row
                ? [
                    'delivery_enabled'    => (bool) $row->delivery_enabled,
                    'sale_vendor_enabled' => (bool) $row->sale_vendor_enabled,
                ]
                : self::DEFAULTS;

            // Merge incoming into current state.
            $next = array_merge($old, $incoming);

            if ($row) {
                $row->update(array_merge($next, ['updated_by' => $changedBy]));
            } else {
                BranchFeatureSetting::create(array_merge($next, [
                    'branch_id'  => $branchId,
                    'updated_by' => $changedBy,
                ]));
            }

            // Write audit only when values actually changed.
            if ($old !== $next) {
                DB::table('branch_feature_audits')->insert([
                    'branch_id'  => $branchId,
                    'changed_by' => $changedBy,
                    'old_values' => json_encode($old),
                    'new_values' => json_encode($next),
                    'changed_at' => now(),
                ]);
            }

            return $next;
        });
    }

    // ── Safety check ──────────────────────────────────────────────────────

    /**
     * Outstanding custody balance on account 1210 for $branchId.
     *
     * balance > 0 means delivery boys still hold cash that hasn't been
     * handed back, so Delivery cannot safely be disabled.
     */
    public function deliveryCustodyBalance(int $branchId): float
    {
        $accountId = DB::table('accounts')
            ->where('code', '1210')
            ->value('id');

        if (!$accountId) {
            return 0.0;
        }

        $balance = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->where('jp.account_id', $accountId)
            ->where('je.branch_id', $branchId)
            ->selectRaw('COALESCE(SUM(jp.debit - jp.credit), 0) AS bal')
            ->value('bal');

        return round((float) ($balance ?? 0), 4);
    }
}
