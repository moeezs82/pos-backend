<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
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
        {--target= : Target SQLite database path}
        {--retained-earnings=3100 : Account code used for retained/opening earnings}
        {--dry-run : Show what would be created without writing the new database}
        {--force : Overwrite target database if it already exists}';

    protected $description = 'Create a fresh SQLite database for the next financial year with master data, stock, and opening balances.';

    private const TARGET_CONNECTION = 'financial_year_target';

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
        $targetDatabase = $this->targetDatabasePath($nextYear);

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
            $this->copyMasterTables($target);
            $openingRows = $this->createOpeningBalances($target, $closeDate);
            $target->statement('PRAGMA foreign_keys = ON');
        } catch (\Throwable $e) {
            $target->statement('PRAGMA foreign_keys = ON');
            throw $e;
        }

        $this->newLine();
        $this->info('Financial year closed successfully.');
        $this->line('Closed up to: '.$closeDate->toDateString());
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
        $this->line('Target database: '.$targetDatabase);
        $this->newLine();

        $this->table(
            ['Table', 'Rows to copy'],
            collect($this->masterTables)
                ->filter(fn (string $table) => Schema::hasTable($table))
                ->map(fn (string $table) => [$table, DB::table($table)->count()])
                ->values()
                ->all()
        );

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
        $target = $this->option('target') ?: database_path('financial_years/pos_'.$nextYear.'.sqlite');
        return str_starts_with($target, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $target)
            ? $target
            : base_path($target);
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

    private function copyMasterTables($target): void
    {
        $this->info('Copying master data and current stock...');

        foreach ($this->masterTables as $table) {
            if (!Schema::hasTable($table) || !$target->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $count = 0;
            DB::table($table)->orderBy($this->orderColumn($table))->chunk(500, function ($rows) use ($target, $table, &$count) {
                $payload = $rows->map(fn ($row) => (array) $row)->all();
                if ($payload !== []) {
                    $target->table($table)->insert($payload);
                    $count += count($payload);
                }
            });

            $this->line('  '.$table.': '.$count);
        }
    }

    private function createOpeningBalances($target, CarbonImmutable $closeDate): int
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

    private function orderColumn(string $table): string
    {
        return Schema::hasColumn($table, 'id') ? 'id' : (Schema::getColumnListing($table)[0] ?? 'rowid');
    }
}
