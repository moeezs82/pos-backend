<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\BranchSubscription;
use App\Services\SubscriptionStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reports the subscription health of every branch.
 *
 * Usage:
 *   php artisan subscriptions:audit
 *   php artisan subscriptions:audit --fix   (backfills missing records only)
 *
 * Exit codes:
 *   0 — all branches have valid, non-locked subscriptions.
 *   1 — one or more branches are locked (expired, suspended, or not configured).
 *
 * Suitable for CI health checks and cron-based alerting.
 */
class AuditBranchSubscriptions extends Command
{
    protected $signature = 'subscriptions:audit
                            {--fix : Create missing subscription records (same as backfill-branches)}';

    protected $description = 'Report branch subscription health. Use --fix to create any missing records.';

    public function handle(SubscriptionStatusService $service): int
    {
        if ($this->option('fix')) {
            $this->call('subscriptions:backfill-branches');
            $this->newLine();
        }

        $branches = Branch::query()
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($branches->isEmpty()) {
            $this->info('No branches found.');
            return self::SUCCESS;
        }

        $rows      = [];
        $hasIssues = false;

        foreach ($branches as $branch) {
            $result    = $service->evaluate((int) $branch->id);
            $sub       = BranchSubscription::where('branch_id', $branch->id)->first();
            $isLocked  = $result['is_locked'];
            $status    = $result['status'];

            if ($isLocked) {
                $hasIssues = true;
            }

            $rows[] = [
                $branch->id,
                $branch->name,
                strtoupper($status),
                $isLocked ? '<error>LOCKED</error>' : '<info>OK</info>',
                $sub?->expires_at
                    ? $sub->expires_at->toDateString()
                    : ($sub ? 'no expiry' : '—'),
                $result['remaining_days'] !== null
                    ? $result['remaining_days'] . ' days'
                    : '—',
            ];
        }

        $this->table(
            ['ID', 'Branch', 'Subscription Status', 'Access', 'Expires', 'Remaining'],
            $rows,
        );

        $total     = $branches->count();
        $locked    = collect($rows)->filter(fn ($r) => str_contains($r[3], 'error'))->count();
        $notCfg    = collect($rows)->filter(fn ($r) => str_contains($r[2], 'NOT_CONFIGURED'))->count();
        $expired   = collect($rows)->filter(fn ($r) => str_contains($r[2], 'EXPIRED'))->count();
        $suspended = collect($rows)->filter(fn ($r) => str_contains($r[2], 'SUSPENDED'))->count();
        $ok        = $total - $locked;

        $this->newLine();
        $this->info("Summary: {$total} branch(es) — {$ok} OK, {$locked} locked");
        if ($notCfg > 0)    $this->warn("  {$notCfg} not configured (run: php artisan subscriptions:backfill-branches)");
        if ($expired > 0)   $this->warn("  {$expired} expired");
        if ($suspended > 0) $this->warn("  {$suspended} suspended");

        return $hasIssues ? self::FAILURE : self::SUCCESS;
    }
}
