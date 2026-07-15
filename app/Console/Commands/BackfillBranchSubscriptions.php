<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\BranchSubscription;
use App\Models\SubscriptionAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent command that creates a default active/no-expiry subscription
 * record for every branch that does not already have one.
 *
 * Usage:
 *   php artisan subscriptions:backfill-branches
 *   php artisan subscriptions:backfill-branches --dry-run
 *
 * Intended for:
 *   - Repairing branches that somehow ended up without a subscription row
 *     after the initial backfill migration ran.
 *   - CI/testing environments where migrations were not run in order.
 *
 * This command is safe to run multiple times.  Branches that already have a
 * subscription row are never touched.
 */
class BackfillBranchSubscriptions extends Command
{
    protected $signature = 'subscriptions:backfill-branches
                            {--dry-run : List affected branches without making changes}';

    protected $description = 'Create default active/no-expiry subscription records for branches that have none.';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Find branches that have no subscription row.
        $missing = Branch::query()
            ->whereNull('deleted_at')
            ->whereNotIn('id', BranchSubscription::select('branch_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($missing->isEmpty()) {
            $this->info('All branches already have a subscription record. Nothing to do.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d branch%s without a subscription record:',
            $missing->count(),
            $missing->count() === 1 ? '' : 'es',
        ));

        $headers = ['ID', 'Name'];
        $rows    = $missing->map(fn ($b) => [$b->id, $b->name])->all();
        $this->table($headers, $rows);

        if ($dryRun) {
            $this->warn('Dry run — no changes made. Remove --dry-run to apply.');
            return self::SUCCESS;
        }

        $now   = now();
        $count = 0;

        DB::transaction(function () use ($missing, $now, &$count) {
            foreach ($missing as $branch) {
                BranchSubscription::create([
                    'branch_id'  => $branch->id,
                    'status'     => 'active',
                    'started_at' => $now,
                    // expires_at intentionally NULL: no expiry until the owner sets one.
                    // managed_by intentionally NULL: this is an automated backfill.
                ]);

                SubscriptionAudit::create([
                    'branch_id'      => $branch->id,
                    'changed_by'     => null,
                    'old_status'     => null,
                    'new_status'     => 'active',
                    'old_expires_at' => null,
                    'new_expires_at' => null,
                    'action'         => 'backfill',
                    'reason'         => 'Auto-created by subscriptions:backfill-branches command.',
                    'metadata'       => ['source' => 'artisan_command'],
                ]);

                $count++;
            }
        });

        $this->info(sprintf('Created %d subscription record%s.', $count, $count === 1 ? '' : 's'));
        return self::SUCCESS;
    }
}
