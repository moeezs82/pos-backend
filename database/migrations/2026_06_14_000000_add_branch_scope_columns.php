<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->prepareSqliteViewsForTableChanges();
            $this->recoverSqliteTempTableIfNeeded('customers');
            $this->recoverSqliteTempTableIfNeeded('vendors');
            $this->recoverSqliteTempTableIfNeeded('delivery_boy_received');
        }

        $this->addBranchColumn(
            table: 'customers',
            indexName: 'customers_branch_name_idx',
            indexColumns: ['branch_id', 'first_name', 'last_name'],
            afterColumn: 'id'
        );

        $this->addBranchColumn(
            table: 'vendors',
            indexName: 'vendors_branch_name_idx',
            indexColumns: ['branch_id', 'first_name', 'last_name'],
            afterColumn: 'id'
        );

        $this->addBranchColumn(
            table: 'delivery_boy_received',
            indexName: 'delivery_received_user_branch_date_idx',
            indexColumns: ['user_id', 'branch_id', 'created_at'],
            afterColumn: 'user_id'
        );

        if (DB::getDriverName() === 'sqlite') {
            $this->recreateSqliteCompatibleViews();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->prepareSqliteViewsForTableChanges();

            $this->dropIndexIfExists('delivery_boy_received', 'delivery_received_user_branch_date_idx');
            $this->dropIndexIfExists('vendors', 'vendors_branch_name_idx');
            $this->dropIndexIfExists('customers', 'customers_branch_name_idx');

            $this->dropSqliteColumnIfExists('delivery_boy_received', 'branch_id');
            $this->dropSqliteColumnIfExists('vendors', 'branch_id');
            $this->dropSqliteColumnIfExists('customers', 'branch_id');

            $this->recreateSqliteCompatibleViews();

            return;
        }

        Schema::table('delivery_boy_received', function (Blueprint $table) {
            if (Schema::hasColumn('delivery_boy_received', 'branch_id')) {
                $table->dropIndex('delivery_received_user_branch_date_idx');
                $table->dropConstrainedForeignId('branch_id');
            }
        });

        Schema::table('vendors', function (Blueprint $table) {
            if (Schema::hasColumn('vendors', 'branch_id')) {
                $table->dropIndex('vendors_branch_name_idx');
                $table->dropConstrainedForeignId('branch_id');
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'branch_id')) {
                $table->dropIndex('customers_branch_name_idx');
                $table->dropConstrainedForeignId('branch_id');
            }
        });
    }

    private function addBranchColumn(string $table, string $indexName, array $indexColumns, string $afterColumn): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, 'branch_id')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            // SQLite cannot add a foreign key constraint with ALTER TABLE, and Laravel's
            // table rebuild can fail when views reference the table. Add a nullable column
            // directly, then create the needed index.
            DB::statement(sprintf('ALTER TABLE "%s" ADD COLUMN "branch_id" INTEGER NULL', $table));
            $this->createIndexIfMissing($table, $indexName, $indexColumns);

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $indexName, $indexColumns, $afterColumn) {
            $blueprint->foreignId('branch_id')
                ->nullable()
                ->after($afterColumn)
                ->constrained('branches')
                ->nullOnDelete();

            $blueprint->index($indexColumns, $indexName);
        });
    }

    private function prepareSqliteViewsForTableChanges(): void
    {
        DB::statement('DROP VIEW IF EXISTS customers_ar_view');
        DB::statement('DROP VIEW IF EXISTS vendors_ap_view');
    }

    private function recoverSqliteTempTableIfNeeded(string $table): void
    {
        $tempTable = '__temp__' . $table;

        if (!Schema::hasTable($table) && Schema::hasTable($tempTable)) {
            DB::statement(sprintf('ALTER TABLE "%s" RENAME TO "%s"', $tempTable, $table));
        }
    }

    private function createIndexIfMissing(string $table, string $indexName, array $columns): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        $quotedColumns = collect($columns)
            ->map(fn (string $column) => '"' . str_replace('"', '""', $column) . '"')
            ->implode(', ');

        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS "%s" ON "%s" (%s)',
            str_replace('"', '""', $indexName),
            str_replace('"', '""', $table),
            $quotedColumns
        ));
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        DB::statement(sprintf('DROP INDEX IF EXISTS "%s"', str_replace('"', '""', $indexName)));
    }

    private function dropSqliteColumnIfExists(string $table, string $column): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        // SQLite 3.35+ supports DROP COLUMN. XAMPP builds used for this project are
        // normally newer. If an older SQLite is used, rollback can be handled manually,
        // but forward migration remains safe.
        DB::statement(sprintf(
            'ALTER TABLE "%s" DROP COLUMN "%s"',
            str_replace('"', '""', $table),
            str_replace('"', '""', $column)
        ));
    }

    private function recreateSqliteCompatibleViews(): void
    {
        $customerFqcn = str_replace('\\', '\\\\', \App\Models\Customer::class);
        $vendorFqcn = str_replace('\\', '\\\\', \App\Models\Vendor::class);

        DB::statement(<<<SQL
CREATE VIEW customers_ar_view AS
SELECT
  c.id AS customer_id,
  TRIM(COALESCE(c.first_name, '') || ' ' || COALESCE(c.last_name, '')) AS customer_name,
  COALESCE(ar.tot_sales, 0.0) AS total_sales,
  COALESCE(ar.tot_receipts, 0.0) AS total_receipts,
  COALESCE(ar.balance, 0.0) AS balance,
  COALESCE(ar.last_activity_at, '1970-01-01') AS last_activity_at
FROM customers c
LEFT JOIN (
  SELECT
    jp.party_id AS customer_id,
    SUM(CASE WHEN jp.debit > 0 THEN jp.debit ELSE 0 END) AS tot_sales,
    SUM(CASE WHEN jp.credit > 0 THEN jp.credit ELSE 0 END) AS tot_receipts,
    SUM(jp.debit - jp.credit) AS balance,
    MAX(jp.created_at) AS last_activity_at
  FROM journal_postings jp
  WHERE jp.party_type IN ('customer', '{$customerFqcn}')
  GROUP BY jp.party_id
) ar ON ar.customer_id = c.id
SQL);

        DB::statement(<<<SQL
CREATE VIEW vendors_ap_view AS
SELECT
  v.id AS vendor_id,
  TRIM(COALESCE(v.first_name, '') || ' ' || COALESCE(v.last_name, '')) AS vendor_name,
  COALESCE(ap.tot_purchases, 0.0) AS total_purchases,
  COALESCE(ap.tot_payments, 0.0) AS total_payments,
  COALESCE(ap.balance, 0.0) AS balance,
  COALESCE(ap.last_activity_at, '1970-01-01') AS last_activity_at
FROM vendors v
LEFT JOIN (
  SELECT
    jp.party_id AS vendor_id,
    SUM(CASE WHEN jp.credit > 0 THEN jp.credit ELSE 0 END) AS tot_purchases,
    SUM(CASE WHEN jp.debit > 0 THEN jp.debit ELSE 0 END) AS tot_payments,
    SUM(jp.credit - jp.debit) AS balance,
    MAX(jp.created_at) AS last_activity_at
  FROM journal_postings jp
  WHERE jp.party_type IN ('vendor', '{$vendorFqcn}')
  GROUP BY jp.party_id
) ap ON ap.vendor_id = v.id
SQL);
    }
};
