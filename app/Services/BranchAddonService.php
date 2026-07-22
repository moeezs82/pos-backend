<?php

namespace App\Services;

use App\Models\BranchAddon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Canonical commercial entitlement service.
 *
 * Add-ons are branch-owned, default to inactive, and never grant employee
 * permissions. Permission checks remain a separate access-control layer.
 */
class BranchAddonService
{
    public const BARCODE_LABELS = 'barcode_labels';

    private const CATALOG = [
        self::BARCODE_LABELS => [
            'label' => 'Barcode Label Printing',
            'description' => 'Print product barcode and price labels using a configured label printer.',
        ],
    ];

    public function __construct(private BranchPermissionStateService $permissionState) {}

    public function catalogForBranch(int $branchId): array
    {
        $states = $this->activeMap($branchId);

        return collect(self::CATALOG)->mapWithKeys(function (array $item, string $key) use ($states) {
            return [$key => array_merge(['key' => $key, 'active' => $states[$key] ?? false], $item)];
        })->all();
    }

    public function activeMap(int $branchId): array
    {
        $defaults = array_fill_keys(array_keys(self::CATALOG), false);
        if (!$this->tableReady()) {
            return $defaults;
        }

        $stored = BranchAddon::query()
            ->where('branch_id', $branchId)
            ->whereIn('addon_key', array_keys(self::CATALOG))
            ->pluck('is_active', 'addon_key')
            ->map(fn ($active) => (bool) $active)
            ->all();

        return array_merge($defaults, $stored);
    }

    /** @return array<int, array<string, bool>> */
    public function activeMaps(array $branchIds): array
    {
        $result = [];
        foreach ($branchIds as $branchId) {
            $result[(int) $branchId] = array_fill_keys(array_keys(self::CATALOG), false);
        }

        if (!$this->tableReady() || empty($branchIds)) {
            return $result;
        }

        BranchAddon::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('addon_key', array_keys(self::CATALOG))
            ->get(['branch_id', 'addon_key', 'is_active'])
            ->each(function (BranchAddon $row) use (&$result) {
                $result[(int) $row->branch_id][$row->addon_key] = (bool) $row->is_active;
            });

        return $result;
    }

    public function isActive(int $branchId, string $addonKey): bool
    {
        $this->assertKnown($addonKey);
        return (bool) ($this->activeMap($branchId)[$addonKey] ?? false);
    }

    public function updateMany(
        int $branchId,
        array $values,
        int $changedBy,
        ?string $reason = null,
        array $metadata = []
    ): array {
        foreach ($values as $key => $active) {
            $this->assertKnown((string) $key);
            if (!is_bool($active)) {
                throw ValidationException::withMessages([
                    "addons.{$key}" => ['The add-on state must be true or false.'],
                ]);
            }
        }

        if (!$this->tableReady()) {
            throw ValidationException::withMessages([
                'addons' => ['Add-on storage is not available. Run the database migrations first.'],
            ]);
        }

        DB::transaction(function () use ($branchId, $values, $changedBy, $reason, $metadata) {
            $branch = $this->permissionState->lockBranch($branchId);
            $changed = false;

            foreach ($values as $key => $active) {
                $row = BranchAddon::query()
                    ->where('branch_id', $branchId)
                    ->where('addon_key', $key)
                    ->lockForUpdate()
                    ->first();

                $oldActive = $row ? (bool) $row->is_active : false;
                if ($oldActive === $active) {
                    continue;
                }

                $now = now();
                $row = $row ?? new BranchAddon([
                    'branch_id' => $branchId,
                    'addon_key' => $key,
                ]);
                $row->fill([
                    'is_active' => $active,
                    'activated_at' => $active ? $now : $row->activated_at,
                    'deactivated_at' => $active ? null : $now,
                    'updated_by' => $changedBy,
                ])->save();

                DB::table('branch_addon_audits')->insert([
                    'branch_id' => $branchId,
                    'addon_key' => $key,
                    'old_active' => $oldActive,
                    'new_active' => $active,
                    'changed_by' => $changedBy,
                    'reason' => $reason,
                    'metadata' => empty($metadata) ? null : json_encode($metadata),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $changed = true;
            }

            if ($changed) {
                // Reuse the existing branch version monitor so logged-in users
                // refresh revoked/granted entitlements without waiting to log in.
                $this->permissionState->bump($branch);
            }
        });

        return $this->catalogForBranch($branchId);
    }

    private function assertKnown(string $addonKey): void
    {
        if (!array_key_exists($addonKey, self::CATALOG)) {
            throw ValidationException::withMessages([
                'addons' => ["Unknown add-on: {$addonKey}."],
            ]);
        }
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('branch_addons');
        } catch (\Throwable) {
            return false;
        }
    }
}
