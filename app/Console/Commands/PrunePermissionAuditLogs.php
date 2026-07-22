<?php

namespace App\Console\Commands;

use App\Models\PermissionAuditLog;
use Illuminate\Console\Command;

class PrunePermissionAuditLogs extends Command
{
    protected $signature = 'permission-audit:prune {--months=12 : Retention period in months}';

    protected $description = 'Delete permission audit records older than the configured retention period';

    public function handle(): int
    {
        $months = max(12, (int) $this->option('months'));
        $deleted = PermissionAuditLog::query()
            ->where('created_at', '<', now()->subMonthsNoOverflow($months))
            ->delete();

        $this->info("Deleted {$deleted} permission audit record(s) older than {$months} months.");

        return self::SUCCESS;
    }
}
