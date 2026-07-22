<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CloseFinancialYear extends Command
{
    protected $signature = 'financial-year:close
        {year : Financial year to close, e.g. 2026}
        {--date= : Closing date. Defaults to YEAR-12-31}
        {--branch-id= : Close only one branch by ID}
        {--branch= : Close only one branch by ID or exact branch name}
        {--target= : Target SQLite database path}
        {--retained-earnings=3100 : Account code used for retained/opening earnings}
        {--dry-run : Show what would be created without writing the new database}
        {--force : Overwrite target database if it already exists}';

    protected $description = 'Create a fresh SQLite database for the next financial year with branch-safe master data, stock, and opening balances.';

    private const TARGET_CONNECTION = 'financial_year_target';

    /**
     * Tables copied into the next-year database.
     * Transaction history is intentionally excluded; opening journal entries are generated instead.
     */
    private array $masterTables = [
        'account_types',
        'branches',
        'users',
        'categories',
        'brands',
        'customers',
        'vendors',
        'accounts',
        'payment_method_accounts',
        'permissions',
        'roles',
        'model_has_permissions',
        'model_has_roles',
        'role_has_permissions',
        'products',
        'product_stocks',
        'registers',
        'printer_settings',
        'branch_subscriptions',
        'subscription_audits',
        'branch_addons',
        'branch_addon_audits',
        'invoice_sequences',
        'personal_access_tokens',
        'sessions',
        'password_reset_tokens',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];

    /**
     * Transaction/history tables.
     *
     * All-branch close: these are not copied; opening balances are generated for every branch.
     * Specific-branch close: rows for the closing branch are not copied, but rows for all
     * other branches are copied as-is so their current year continues untouched.
     */
    private array $transactionTables = [
        'sales',
        'sale_items',
        'sale_returns',
        'sale_return_items',
        'sale_return_refunds',
        'sale_refunds',
        'purchases',
        'purchase_items',
        'purchase_claims',
        'purchase_claim_items',
        'purchase_claim_receipts',
        'receipts',
        'vendor_payments',
        'cash_transactions',
        'cash_ledger_entries',
        'register_shifts',
        'shift_cash_movements',
        'stock_movements',
        'delivery_boy_received',
        'journal_entries',
        'journal_postings',
    ];

    /** @var array<string, array<int>> */
    private array $scopedIds = [];

    private ?object $branchScope = null;

    /**
     * Framework bookkeeping/cache tables that are deliberately rebuilt or
     * allowed to start empty in the new database.
     */
    private array $ignoredTables = [
        'migrations',
        'cache',
        'cache_locks',
    ];

    public function handle(): int
    {
        $year = (int) $this->argument('year');
        if ($year < 2000 || $year > 2100) {
            $this->error('Please provide a valid closing year, for example: 2026');
            return self::FAILURE;
        }

        $closeDate = $this->closeDate($year);
        $nextYear = $closeDate->addDay()->year;
        $sourceDatabase = $this->sourceDatabasePath();
        $this->branchScope = $this->resolveBranchScope();
        $this->prepareScopedIds();
        $targetDatabase = $this->targetDatabasePath($nextYear);
        $this->assertEverySourceTableHasPolicy();

        if ($this->option('dry-run')) {
            return $this->dryRun($closeDate, $sourceDatabase, $targetDatabase);
        }

        $this->prepareTargetDatabase($targetDatabase);
        $this->configureTargetConnection($targetDatabase);

        $this->info('Running migrations on the new database...');
        Artisan::call('migrate', [
            '--database' => self::TARGET_CONNECTION,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $target = DB::connection(self::TARGET_CONNECTION);
        $target->statement('PRAGMA foreign_keys = OFF');

        try {
            $this->assertEverySourceTableIsClassified($target);
            $this->copyMasterTables($target);
            $this->copyOpenBranchTransactionHistory($target);
            $openingRows = $this->createOpeningBalances($target, $closeDate);
            $target->statement('PRAGMA foreign_keys = ON');
            $this->assertTargetForeignKeysAreValid($target);
        } catch (\Throwable $e) {
            $target->statement('PRAGMA foreign_keys = ON');
            DB::purge(self::TARGET_CONNECTION);
            if (file_exists($targetDatabase)) {
                @unlink($targetDatabase);
            }
            throw $e;
        }

        $this->newLine();
        $this->info('Financial year closed successfully.');
        $this->line('Closed up to: '.$closeDate->toDateString());
        $this->line('Scope: '.$this->scopeLabel());
        $this->line('New database: '.$targetDatabase);
        $this->line('Opening journal rows: '.$openingRows);
        $this->newLine();
        $this->warn('Your current .env was not changed. Point DB_DATABASE to the new file when you are ready to start the next year.');

        return self::SUCCESS;
    }

    private function dryRun(CarbonImmutable $closeDate, string $sourceDatabase, string $targetDatabase): int
    {
        $this->info('Dry run only. No database will be created.');
        $this->line('Source database: '.$sourceDatabase);
        $this->line('Close date: '.$closeDate->toDateString());
        $this->line('Scope: '.$this->scopeLabel());
        $this->line('Target database: '.$targetDatabase);
        $this->newLine();

        $this->table(
            ['Master/setup table', 'Rows to copy'],
            collect($this->masterTables)
                ->filter(fn (string $table) => Schema::hasTable($table))
                ->map(fn (string $table) => [$table, $this->sourceQueryForTable($table)->count()])
                ->values()
                ->all()
        );

        if ($this->branchScope) {
            $this->newLine();
            $this->warn('Specific branch close: transaction history for Branch #'.$this->branchScope->id.' will be closed into opening balances.');
            $this->warn('All other branches will keep their existing transaction/history rows in the new database.');
            $this->table(
                ['Open branch history table', 'Rows to keep for other branches'],
                collect($this->transactionTables)
                    ->filter(fn (string $table) => Schema::hasTable($table))
                    ->map(fn (string $table) => [$table, $this->sourceTransactionQueryForTable($table)->count()])
                    ->values()
                    ->all()
            );
        }

        $balances = $this->openingBalanceGroups($closeDate);
        $this->line('Opening balance groups: '.$balances->count());
        $this->line('Opening balance raw total: '.number_format((float) $balances->sum('balance'), 2));

        return self::SUCCESS;
    }

    private function closeDate(int $year): CarbonImmutable
    {
        $date = $this->option('date') ?: $year.'-12-31';
        $closeDate = CarbonImmutable::parse($date)->startOfDay();

        if ($closeDate->year !== $year) {
            throw new RuntimeException('The --date option must be inside the closing year.');
        }

        return $closeDate;
    }

    private function sourceDatabasePath(): string
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('financial-year:close currently supports SQLite databases only.');
        }

        $database = DB::connection()->getDatabaseName();
        if (!$database || $database === ':memory:') {
            throw new RuntimeException('The source SQLite database must be a file, not :memory:.');
        }

        return realpath($database) ?: $database;
    }

    private function targetDatabasePath(int $nextYear): string
    {
        $target = $this->option('target') ?: database_path('financial_years/pos_'.$nextYear.$this->targetBranchSuffix().'.sqlite');

        return str_starts_with($target, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $target)
            ? $target
            : base_path($target);
    }

    private function targetBranchSuffix(): string
    {
        return $this->branchScope ? '_branch_'.$this->branchScope->id.'_closed' : '';
    }

    private function prepareTargetDatabase(string $targetDatabase): void
    {
        $source = $this->sourceDatabasePath();
        $targetReal = realpath($targetDatabase) ?: $targetDatabase;

        if ($source === $targetReal) {
            throw new RuntimeException('Target database cannot be the same file as the current database.');
        }

        if (file_exists($targetDatabase)) {
            if (!$this->option('force')) {
                throw new RuntimeException('Target database already exists. Use --force to overwrite it.');
            }

            unlink($targetDatabase);
        }

        $directory = dirname($targetDatabase);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        touch($targetDatabase);
    }

    private function configureTargetConnection(string $targetDatabase): void
    {
        Config::set('database.connections.'.self::TARGET_CONNECTION, [
            'driver' => 'sqlite',
            'database' => $targetDatabase,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge(self::TARGET_CONNECTION);
        DB::reconnect(self::TARGET_CONNECTION);
    }

    private function copyMasterTables(ConnectionInterface $target): void
    {
        $this->info('Copying master data and current stock...');

        foreach ($this->masterTables as $table) {
            if (!Schema::hasTable($table) || !$target->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $query = $this->sourceQueryForTable($table);
            $expectedCount = (clone $query)->count();
            $sourceColumns = Schema::getColumnListing($table);
            $targetColumns = $target->getSchemaBuilder()->getColumnListing($table);
            $copyColumns = array_values(array_intersect($sourceColumns, $targetColumns));
            $count = 0;

            if ($copyColumns === []) {
                $this->line('  '.$table.': 0');
                continue;
            }

            /*
             * The target database is migrated before copying data. Some later
             * migrations may seed required setup rows (for example ASSET in
             * account_types or Delivery Boy Cash in Transit in accounts). Since
             * this command preserves source IDs exactly, those migration-created
             * rows must be removed before source master data is inserted.
             */
            $this->clearTargetTable($target, $table);

            $query->orderBy($this->orderColumn($table))->chunk(500, function ($rows) use ($target, $table, $copyColumns, &$count) {
                $payload = [];

                foreach ($rows as $row) {
                    $payload[] = $this->transformRowForTarget($table, (array) $row, $copyColumns);
                }

                if ($payload !== []) {
                    $target->table($table)->insert($payload);
                    $count += count($payload);
                }
            });

            $this->line('  '.$table.': '.$count);
            $this->assertCopiedRowCount($target, $table, $expectedCount);
        }
    }

    private function clearTargetTable(ConnectionInterface $target, string $table): void
    {
        try {
            $target->table($table)->delete();
        } catch (\Throwable) {
            // If a table cannot be cleared for an unexpected schema-specific reason,
            // continue to normal insert so the original database error remains visible.
        }
    }

    private function transformRowForTarget(string $table, array $row, array $copyColumns): array
    {
        $payload = array_intersect_key($row, array_flip($copyColumns));

        // Preserve user branch assignments exactly.
        // A specific-branch close still creates a full company database; only the selected
        // branch's transaction history is closed, while other branches remain untouched.

        return $payload;
    }

    private function sourceQueryForTable(string $table): Builder
    {
        /*
         * Master/setup data is always copied for the whole company.
         *
         * This is important for partial branch close:
         * closing Branch #1 must not create a branch-only database. The target database
         * must still contain all other branches, users, roles, customers, vendors,
         * products and stock exactly as they were.
         */
        return DB::table($table);
    }

    private function copyOpenBranchTransactionHistory(ConnectionInterface $target): void
    {
        if (!$this->branchScope) {
            return;
        }

        $this->info('Copying transaction history for branches that are not being closed...');

        foreach ($this->transactionTables as $table) {
            if (!Schema::hasTable($table) || !$target->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $query = $this->sourceTransactionQueryForTable($table);
            $expectedCount = (clone $query)->count();
            $sourceColumns = Schema::getColumnListing($table);
            $targetColumns = $target->getSchemaBuilder()->getColumnListing($table);
            $copyColumns = array_values(array_intersect($sourceColumns, $targetColumns));
            $count = 0;

            if ($copyColumns === []) {
                $this->line('  '.$table.': 0');
                continue;
            }

            $query->orderBy($this->orderColumn($table))->chunk(500, function ($rows) use ($target, $table, $copyColumns, &$count) {
                $payload = [];

                foreach ($rows as $row) {
                    $payload[] = $this->transformRowForTarget($table, (array) $row, $copyColumns);
                }

                if ($payload !== []) {
                    $target->table($table)->insert($payload);
                    $count += count($payload);
                }
            });

            $this->line('  '.$table.': '.$count);
            $this->assertCopiedRowCount($target, $table, $expectedCount);
        }
    }

    private function sourceTransactionQueryForTable(string $table): Builder
    {
        $query = DB::table($table);
        $branchId = $this->branchScope?->id;

        if (!$branchId) {
            return $query->whereRaw('1 = 0');
        }

        return match ($table) {
            'sale_items' => $this->whereChildParentOpenBranch($query, 'sale_id', 'sales', $branchId),
            'sale_returns' => $this->whereOwnOrParentOpenBranch($query, 'sale_returns', 'sale_id', 'sales', $branchId),
            'sale_return_items' => $this->whereChildParentOpenBranch($query, 'sale_return_id', 'sale_returns', $branchId),
            'sale_return_refunds' => $this->whereChildParentOpenBranch($query, 'sale_return_id', 'sale_returns', $branchId),
            'sale_refunds' => $this->whereChildParentOpenBranch($query, 'sale_id', 'sales', $branchId),
            'purchase_items' => $this->whereChildParentOpenBranch($query, 'purchase_id', 'purchases', $branchId),
            'purchase_claims' => $this->whereOwnOrParentOpenBranch($query, 'purchase_claims', 'purchase_id', 'purchases', $branchId),
            'purchase_claim_items' => $this->whereChildParentOpenBranch($query, 'purchase_claim_id', 'purchase_claims', $branchId),
            'purchase_claim_receipts' => $this->whereChildParentOpenBranch($query, 'purchase_claim_id', 'purchase_claims', $branchId),
            'receipts' => $this->whereOwnOrParentOpenBranch($query, 'receipts', 'sale_id', 'sales', $branchId),
            'vendor_payments' => $this->whereOwnOrParentOpenBranch($query, 'vendor_payments', 'purchase_id', 'purchases', $branchId),
            'journal_postings' => $this->whereChildParentOpenBranch($query, 'journal_entry_id', 'journal_entries', $branchId),
            'shift_cash_movements' => $this->whereChildParentOpenBranch($query, 'register_shift_id', 'register_shifts', $branchId),
            'delivery_boy_received' => $this->whereDeliveryReceivedOpenBranch($query, $branchId),
            default => $this->whereOpenBranch($query, $table, $branchId),
        };
    }

    private function whereOpenBranch(Builder $query, string $table, int $closingBranchId): Builder
    {
        if (!Schema::hasColumn($table, 'branch_id')) {
            return $query;
        }

        return $query->where(function ($q) use ($closingBranchId) {
            $q->whereNull('branch_id')
                ->orWhere('branch_id', '<>', $closingBranchId);
        });
    }

    private function whereChildParentOpenBranch(Builder $query, string $foreignKey, string $parentTable, int $closingBranchId): Builder
    {
        if (!Schema::hasColumn($query->from, $foreignKey) || !Schema::hasTable($parentTable)) {
            return $query->whereRaw('1 = 0');
        }

        if (!Schema::hasColumn($parentTable, 'branch_id')) {
            return $query;
        }

        $parentIds = DB::table($parentTable)
            ->select('id')
            ->where(function ($q) use ($closingBranchId) {
                $q->whereNull('branch_id')
                    ->orWhere('branch_id', '<>', $closingBranchId);
            });

        return $query->whereIn($foreignKey, $parentIds);
    }

    private function whereOwnOrParentOpenBranch(Builder $query, string $table, string $foreignKey, string $parentTable, int $closingBranchId): Builder
    {
        if (!Schema::hasColumn($table, 'branch_id')) {
            return $this->whereChildParentOpenBranch($query, $foreignKey, $parentTable, $closingBranchId);
        }

        if (!Schema::hasColumn($table, $foreignKey) || !Schema::hasTable($parentTable) || !Schema::hasColumn($parentTable, 'branch_id')) {
            return $this->whereOpenBranch($query, $table, $closingBranchId);
        }

        $openParentIds = DB::table($parentTable)
            ->select('id')
            ->where(function ($q) use ($closingBranchId) {
                $q->whereNull('branch_id')
                    ->orWhere('branch_id', '<>', $closingBranchId);
            });

        return $query->where(function ($q) use ($closingBranchId, $foreignKey, $openParentIds) {
            $q->where(function ($ownBranch) use ($closingBranchId) {
                $ownBranch->whereNotNull('branch_id')
                    ->where('branch_id', '<>', $closingBranchId);
            })->orWhere(function ($parentBranch) use ($foreignKey, $openParentIds) {
                $parentBranch->whereNull('branch_id')
                    ->whereIn($foreignKey, $openParentIds);
            })->orWhere(function ($globalRow) use ($foreignKey) {
                $globalRow->whereNull('branch_id')
                    ->whereNull($foreignKey);
            });
        });
    }

    private function whereDeliveryReceivedOpenBranch(Builder $query, int $closingBranchId): Builder
    {
        if (Schema::hasColumn('delivery_boy_received', 'branch_id')) {
            return $this->whereOpenBranch($query, 'delivery_boy_received', $closingBranchId);
        }

        if (Schema::hasColumn('delivery_boy_received', 'user_id') && Schema::hasTable('users') && Schema::hasColumn('users', 'branch_id')) {
            $openBranchUsers = DB::table('users')
                ->select('id')
                ->where(function ($q) use ($closingBranchId) {
                    $q->whereNull('branch_id')
                        ->orWhere('branch_id', '<>', $closingBranchId);
                });

            return $query->whereIn('user_id', $openBranchUsers);
        }

        return $query;
    }

    private function whereBranch(Builder $query, string $table, int $branchId): Builder
    {
        return Schema::hasColumn($table, 'branch_id')
            ? $query->where('branch_id', $branchId)
            : $query;
    }

    private function whereBranchOrGlobal(Builder $query, string $table, int $branchId): Builder
    {
        return Schema::hasColumn($table, 'branch_id')
            ? $query->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
            : $query;
    }

    private function whereProductsScoped(Builder $query, int $branchId): Builder
    {
        if (!Schema::hasColumn('products', 'branch_id')) {
            return $query;
        }

        return $query->where('branch_id', $branchId);
    }

    private function whereProductStocksScoped(Builder $query, int $branchId): Builder
    {
        $query = Schema::hasColumn('product_stocks', 'branch_id')
            ? $query->where('branch_id', $branchId)
            : $query;

        return $this->ids('products') === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('product_id', $this->ids('products'));
    }

    private function whereModelRolePivotScoped(Builder $query): Builder
    {
        $rolePivotKey = $this->rolePivotKey();
        $modelMorphKey = $this->modelMorphKey();

        if (!Schema::hasColumn('model_has_roles', $rolePivotKey) || !Schema::hasColumn('model_has_roles', $modelMorphKey)) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereIn($rolePivotKey, $this->ids('roles'))
            ->whereIn($modelMorphKey, $this->ids('users'))
            ->when(Schema::hasColumn('model_has_roles', 'model_type'), fn ($q) => $q->where('model_type', User::class));
    }

    private function whereModelPermissionPivotScoped(Builder $query): Builder
    {
        $modelMorphKey = $this->modelMorphKey();

        if (!Schema::hasColumn('model_has_permissions', $modelMorphKey)) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereIn($modelMorphKey, $this->ids('users'))
            ->when(Schema::hasColumn('model_has_permissions', 'model_type'), fn ($q) => $q->where('model_type', User::class));
    }

    private function whereRolePermissionPivotScoped(Builder $query): Builder
    {
        $rolePivotKey = $this->rolePivotKey();

        return Schema::hasColumn('role_has_permissions', $rolePivotKey)
            ? $query->whereIn($rolePivotKey, $this->ids('roles'))
            : $query->whereRaw('1 = 0');
    }

    private function createOpeningBalances(ConnectionInterface $target, CarbonImmutable $closeDate): int
    {
        $this->info('Creating opening balances...');

        $retainedAccount = DB::table('accounts')
            ->where('code', (string) $this->option('retained-earnings'))
            ->first();

        if (!$retainedAccount) {
            throw new RuntimeException('Retained earnings account not found. Check --retained-earnings.');
        }

        $groups = $this->openingBalanceGroups($closeDate);
        $byBranch = [];

        foreach ($groups as $row) {
            $branchKey = $row->branch_id === null ? 'null' : (string) $row->branch_id;
            $balance = round((float) $row->balance, 2);

            if (abs($balance) < 0.005) {
                continue;
            }

            $byBranch[$branchKey] ??= [
                'branch_id' => $row->branch_id,
                'lines' => [],
            ];

            if (in_array($row->type_code, ['INCOME', 'EXPENSE'], true)) {
                continue;
            }

            $this->addSignedLine($byBranch[$branchKey]['lines'], [
                'account_id' => (int) $row->account_id,
                'party_type' => $row->party_type,
                'party_id' => $row->party_id,
            ], $balance);
        }

        $insertedPostings = 0;

        foreach ($byBranch as $branch) {
            $sum = round(array_sum(array_column($branch['lines'], 'balance')), 2);
            if (abs($sum) >= 0.005) {
                $this->addSignedLine($branch['lines'], [
                    'account_id' => (int) $retainedAccount->id,
                    'party_type' => null,
                    'party_id' => null,
                ], -$sum);
            }

            $lines = array_values(array_filter($branch['lines'], fn (array $line) => abs($line['balance']) >= 0.005));
            if ($lines === []) {
                continue;
            }

            $entryId = $target->table('journal_entries')->insertGetId([
                'entry_date' => $closeDate->toDateString(),
                // 'memo' => 'Opening balances from financial year close '.$closeDate->year.$this->openingMemoBranchSuffix($branch['branch_id']),
                'memo' => 'Opening balances from financial year close '.$closeDate->year,
                'branch_id' => $branch['branch_id'],
                'reference_type' => null,
                'reference_id' => null,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($lines as $line) {
                $balance = round((float) $line['balance'], 2);

                $target->table('journal_postings')->insert([
                    'journal_entry_id' => $entryId,
                    'account_id' => $line['account_id'],
                    'debit' => $balance > 0 ? $balance : 0,
                    'credit' => $balance < 0 ? abs($balance) : 0,
                    'party_type' => $line['party_type'],
                    'party_id' => $line['party_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $insertedPostings++;
            }
        }

        return $insertedPostings;
    }

    private function openingBalanceGroups(CarbonImmutable $closeDate)
    {
        return DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jp.account_id')
            ->join('account_types as at', 'at.id', '=', 'a.account_type_id')
            ->whereDate('je.entry_date', '<=', $closeDate->toDateString())
            ->when($this->branchScope, fn ($q) => $q->where('je.branch_id', (int) $this->branchScope->id))
            ->groupBy('je.branch_id', 'jp.account_id', 'jp.party_type', 'jp.party_id', 'at.code')
            ->selectRaw('
                je.branch_id,
                jp.account_id,
                jp.party_type,
                jp.party_id,
                at.code as type_code,
                ROUND(SUM(COALESCE(jp.debit, 0) - COALESCE(jp.credit, 0)), 2) as balance
            ')
            ->havingRaw('ABS(balance) >= 0.005')
            ->get();
    }

    private function addSignedLine(array &$lines, array $identity, float $balance): void
    {
        $key = implode('|', [
            $identity['account_id'],
            $identity['party_type'] ?? '',
            $identity['party_id'] ?? '',
        ]);

        $lines[$key] ??= $identity + ['balance' => 0.0];
        $lines[$key]['balance'] = round($lines[$key]['balance'] + $balance, 2);
    }

    private function resolveBranchScope(): ?object
    {
        $rawBranchId = $this->option('branch-id');
        $rawBranch = $this->option('branch');

        $raw = $rawBranchId !== null && $rawBranchId !== '' ? $rawBranchId : $rawBranch;

        if ($raw === null || $raw === '' || strtolower((string) $raw) === 'all') {
            return null;
        }

        if (!Schema::hasTable('branches')) {
            throw new RuntimeException('Branches table does not exist. Cannot close a specific branch.');
        }

        $query = DB::table('branches')->select(['id', 'name']);
        $branch = is_numeric($raw)
            ? $query->where('id', (int) $raw)->first()
            : $query->where('name', (string) $raw)->first();

        if (!$branch) {
            throw new RuntimeException('Selected branch was not found. Use --branch-id=ID or --branch="Exact Branch Name".');
        }

        if ($rawBranchId !== null && $rawBranchId !== '' && $rawBranch !== null && $rawBranch !== '') {
            $branchFromAlias = is_numeric($rawBranch)
                ? DB::table('branches')->select(['id', 'name'])->where('id', (int) $rawBranch)->first()
                : DB::table('branches')->select(['id', 'name'])->where('name', (string) $rawBranch)->first();

            if (!$branchFromAlias || (int) $branchFromAlias->id !== (int) $branch->id) {
                throw new RuntimeException('--branch-id and --branch point to different branches. Provide only one branch option.');
            }
        }

        return $branch;
    }

    private function prepareScopedIds(): void
    {
        $this->scopedIds = [];

        if (!$this->branchScope) {
            return;
        }

        $branchId = (int) $this->branchScope->id;
        $this->scopedIds['branches'] = [$branchId];
        $this->scopedIds['master_roles'] = $this->masterRoleIds();
        $this->scopedIds['roles'] = $this->roleIdsForBranch($branchId, $this->scopedIds['master_roles']);
        $this->scopedIds['master_users'] = $this->masterUserIds($this->scopedIds['master_roles']);
        $this->scopedIds['users'] = $this->userIdsForBranch($branchId, $this->scopedIds['master_users']);
        $this->scopedIds['products'] = $this->productIdsForBranch($branchId);
    }

    /** @return array<int> */
    private function masterRoleIds(): array
    {
        if (!Schema::hasTable('roles')) {
            return [];
        }

        return DB::table('roles')
            ->select(['id', 'name'])
            ->get()
            ->filter(fn ($role) => User::isMasterAdminRoleName((string) $role->name))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @param array<int> $masterRoleIds @return array<int> */
    private function roleIdsForBranch(int $branchId, array $masterRoleIds): array
    {
        if (!Schema::hasTable('roles')) {
            return [];
        }

        $ids = collect($masterRoleIds);

        if (Schema::hasColumn('roles', 'branch_id')) {
            $ids = $ids->merge(DB::table('roles')->where('branch_id', $branchId)->pluck('id'));
        } else {
            $ids = $ids->merge(DB::table('roles')->pluck('id'));
        }

        return $ids->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @param array<int> $masterRoleIds @return array<int> */
    private function masterUserIds(array $masterRoleIds): array
    {
        if ($masterRoleIds === [] || !Schema::hasTable('model_has_roles')) {
            return [];
        }

        $rolePivotKey = $this->rolePivotKey();
        $modelMorphKey = $this->modelMorphKey();

        if (!Schema::hasColumn('model_has_roles', $rolePivotKey) || !Schema::hasColumn('model_has_roles', $modelMorphKey)) {
            return [];
        }

        return DB::table('model_has_roles')
            ->whereIn($rolePivotKey, $masterRoleIds)
            ->when(Schema::hasColumn('model_has_roles', 'model_type'), fn ($q) => $q->where('model_type', User::class))
            ->pluck($modelMorphKey)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<int> $masterUserIds @return array<int> */
    private function userIdsForBranch(int $branchId, array $masterUserIds): array
    {
        if (!Schema::hasTable('users')) {
            return [];
        }

        $ids = collect($masterUserIds);

        if (Schema::hasColumn('users', 'branch_id')) {
            $ids = $ids->merge(DB::table('users')->where('branch_id', $branchId)->pluck('id'));
        } else {
            $ids = $ids->merge(DB::table('users')->pluck('id'));
        }

        return $ids->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return array<int> */
    private function productIdsForBranch(int $branchId): array
    {
        if (!Schema::hasTable('products')) {
            return [];
        }

        if (Schema::hasColumn('products', 'branch_id')) {
            return DB::table('products')
                ->where('branch_id', $branchId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        return DB::table('products')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return array<int> */
    private function ids(string $key): array
    {
        return $this->scopedIds[$key] ?? [];
    }

    private function isMasterUserId(int $userId): bool
    {
        return in_array($userId, $this->ids('master_users'), true);
    }

    private function scopeLabel(): string
    {
        if (!$this->branchScope) {
            return 'All branches';
        }

        return 'Branch #'.$this->branchScope->id.' - '.$this->branchScope->name;
    }

    private function openingMemoBranchSuffix(?int $branchId): string
    {
        if (!$this->branchScope || !$branchId) {
            return '';
        }

        return ' for branch '.$this->branchScope->name;
    }

    private function rolePivotKey(): string
    {
        return config('permission.column_names.role_pivot_key') ?: 'role_id';
    }

    private function modelMorphKey(): string
    {
        return config('permission.column_names.model_morph_key') ?: 'model_id';
    }

    private function orderColumn(string $table): string
    {
        return Schema::hasColumn($table, 'id') ? 'id' : (Schema::getColumnListing($table)[0] ?? 'rowid');
    }

    private function assertEverySourceTableIsClassified(ConnectionInterface $target): void
    {
        $sourceTables = collect(Schema::getTableListing())
            ->map(fn (string $table) => $this->unqualifiedTableName($table))
            ->filter(fn (string $table) => !str_starts_with($table, 'sqlite_'))
            ->unique();

        $targetTables = collect($target->getSchemaBuilder()->getTableListing())
            ->map(fn (string $table) => $this->unqualifiedTableName($table))
            ->unique();

        $missingFromTarget = $sourceTables
            ->diff($this->ignoredTables)
            ->diff($targetTables)
            ->sort()
            ->values();

        if ($missingFromTarget->isNotEmpty()) {
            throw new RuntimeException(
                'Financial-year close aborted: these source tables do not exist in the migrated target: '
                .$missingFromTarget->implode(', ')
            );
        }
    }

    private function assertEverySourceTableHasPolicy(): void
    {
        $classified = array_unique(array_merge(
            $this->masterTables,
            $this->transactionTables,
            $this->ignoredTables,
        ));

        $unclassified = collect(Schema::getTableListing())
            ->map(fn (string $table) => $this->unqualifiedTableName($table))
            ->filter(fn (string $table) => !str_starts_with($table, 'sqlite_'))
            ->diff($classified)
            ->sort()
            ->values();

        if ($unclassified->isNotEmpty()) {
            throw new RuntimeException(
                'Financial-year close aborted: these tables have no rollover policy: '
                .$unclassified->implode(', ')
                .'. Classify each table as master/setup, transaction/history, or intentionally ignored.'
            );
        }
    }

    private function assertCopiedRowCount(
        ConnectionInterface $target,
        string $table,
        int $expectedCount
    ): void {
        $actualCount = $target->table($table)->count();

        if ($actualCount !== $expectedCount) {
            throw new RuntimeException(
                "Financial-year close row-count mismatch for {$table}: expected {$expectedCount}, copied {$actualCount}."
            );
        }
    }

    private function assertTargetForeignKeysAreValid(ConnectionInterface $target): void
    {
        $violations = $target->select('PRAGMA foreign_key_check');

        if ($violations !== []) {
            $preview = collect($violations)
                ->take(10)
                ->map(fn ($row) => ($row->table ?? 'unknown').' row '.($row->rowid ?? '?'))
                ->implode(', ');

            throw new RuntimeException(
                'Financial-year close produced foreign-key violations: '.$preview
            );
        }
    }

    private function unqualifiedTableName(string $table): string
    {
        $parts = explode('.', $table);

        return (string) end($parts);
    }
}
