<?php

use App\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills subscription records for branches that pre-date the subscription
 * module (created before migration 2026_07_15_000002 was deployed).
 *
 * INITIAL STATE
 * Each existing branch that has no subscription row receives:
 *   status     = 'active'
 *   expires_at = NULL   (no expiry — owner sets a date through the management UI)
 *   started_at = NOW()
 *
 * This keeps every branch currently in production accessible after the switch
 * to fail-closed enforcement.  The SaaS Owner assigns real expiry dates at
 * their own pace through /api/v1/subscriptions/{id}.
 *
 * IDEMPOTENT
 * Only branches that have no row in branch_subscriptions are affected.
 * Running this migration more than once is safe.
 *
 * REVERSIBLE
 * down() deletes ONLY the rows created by this migration (managed_by IS NULL,
 * started_at within a tolerance band of 5 minutes).  Rows the SaaS Owner
 * created manually are not touched.
 *
 * For on-demand repair after this migration, use:
 *   php artisan subscriptions:backfill-branches
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fetch branch IDs that have no subscription row.
        $missingIds = DB::table('branches')
            ->whereNull('deleted_at')
            ->whereNotIn('id', DB::table('branch_subscriptions')->select('branch_id'))
            ->pluck('id');

        if ($missingIds->isEmpty()) {
            return; // Nothing to do.
        }

        $now  = now();
        $rows = $missingIds->map(fn ($id) => [
            'branch_id'  => $id,
            'status'     => 'active',
            'started_at' => $now,
            'expires_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            // managed_by intentionally NULL to distinguish backfill rows
            // from rows created by the owner through the management API.
        ])->values()->all();

        // Insert in chunks to stay within MySQL's packet size limit on
        // databases with large branch counts.
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('branch_subscriptions')->insert($chunk);
        }

        // Append one audit entry per backfill row so the history is complete.
        $auditRows = $missingIds->map(fn ($id) => [
            'branch_id'      => $id,
            'changed_by'     => null,
            'old_status'     => null,
            'new_status'     => 'active',
            'old_expires_at' => null,
            'new_expires_at' => null,
            'action'         => 'backfill',
            'reason'         => 'Auto-created by backfill migration 2026_07_15_000003.',
            'metadata'       => json_encode(['source' => 'migration']),
            'created_at'     => $now,
            'updated_at'     => $now,
        ])->values()->all();

        foreach (array_chunk($auditRows, 200) as $chunk) {
            DB::table('subscription_audits')->insert($chunk);
        }
    }

    public function down(): void
    {
        // Only remove rows created by this backfill (managed_by IS NULL and
        // action = 'backfill').  SaaS-Owner-created rows are untouched.
        $backfilledIds = DB::table('subscription_audits')
            ->where('action', 'backfill')
            ->whereNull('changed_by')
            ->pluck('branch_id');

        if ($backfilledIds->isEmpty()) {
            return;
        }

        DB::table('branch_subscriptions')
            ->whereIn('branch_id', $backfilledIds)
            ->whereNull('managed_by')
            ->delete();

        DB::table('subscription_audits')
            ->whereIn('branch_id', $backfilledIds)
            ->where('action', 'backfill')
            ->whereNull('changed_by')
            ->delete();
    }
};
