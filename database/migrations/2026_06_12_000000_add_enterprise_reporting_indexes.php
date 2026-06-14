<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->index('sales', ['created_at', 'branch_id', 'status'], 'idx_reports_sales_date_branch_status');
        $this->index('sales', ['customer_id', 'salesman_id', 'vendor_id'], 'idx_reports_sales_parties');
        $this->index('sale_items', ['sale_id', 'product_id'], 'idx_reports_sale_items_sale_product');
        $this->index('sale_returns', ['created_at', 'branch_id', 'status'], 'idx_reports_sale_returns_date_branch');
        $this->index('receipts', ['created_at', 'branch_id', 'method'], 'idx_reports_receipts_date_branch_method');

        $this->index('purchases', ['created_at', 'branch_id', 'status'], 'idx_reports_purchases_date_branch_status');
        $this->index('purchases', ['vendor_id', 'receive_status'], 'idx_reports_purchases_vendor_receive');
        $this->index('purchase_items', ['purchase_id', 'product_id'], 'idx_reports_purchase_items_purchase_product');
        $this->index('vendor_payments', ['created_at', 'branch_id', 'method'], 'idx_reports_vendor_payments_date_branch');
        $this->index('purchase_claims', ['created_at', 'branch_id', 'status'], 'idx_reports_purchase_claims_date_branch');

        $this->index('product_stocks', ['branch_id', 'product_id'], 'idx_reports_product_stocks_branch_product');
        $this->index('stock_movements', ['created_at', 'branch_id', 'product_id', 'type'], 'idx_reports_stock_movements_date_branch');

        $this->index('cash_transactions', ['created_at', 'branch_id', 'type', 'method'], 'idx_reports_cash_transactions_date_branch');
        $this->index('journal_entries', ['created_at', 'branch_id'], 'idx_reports_journal_entries_date_branch');
        $this->index('journal_postings', ['journal_entry_id', 'account_id'], 'idx_reports_journal_postings_entry_account');
    }

    public function down(): void
    {
        foreach ([
            ['sales', 'idx_reports_sales_date_branch_status'],
            ['sales', 'idx_reports_sales_parties'],
            ['sale_items', 'idx_reports_sale_items_sale_product'],
            ['sale_returns', 'idx_reports_sale_returns_date_branch'],
            ['receipts', 'idx_reports_receipts_date_branch_method'],
            ['purchases', 'idx_reports_purchases_date_branch_status'],
            ['purchases', 'idx_reports_purchases_vendor_receive'],
            ['purchase_items', 'idx_reports_purchase_items_purchase_product'],
            ['vendor_payments', 'idx_reports_vendor_payments_date_branch'],
            ['purchase_claims', 'idx_reports_purchase_claims_date_branch'],
            ['product_stocks', 'idx_reports_product_stocks_branch_product'],
            ['stock_movements', 'idx_reports_stock_movements_date_branch'],
            ['cash_transactions', 'idx_reports_cash_transactions_date_branch'],
            ['journal_entries', 'idx_reports_journal_entries_date_branch'],
            ['journal_postings', 'idx_reports_journal_postings_entry_account'],
        ] as [$table, $index]) {
            $this->dropIndex($table, $index);
        }
    }

    private function index(string $table, array $columns, string $name): void
    {
        if (!Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                $blueprint->index($columns, $name);
            });
        } catch (Throwable) {
            // Ignore duplicate/driver-specific index errors so this production-hardening
            // migration never blocks deployment on an already indexed database.
        }
    }

    private function dropIndex(string $table, string $name): void
    {
        if (!Schema::hasTable($table) || !$this->indexExists($table, $name)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropIndex($name);
            });
        } catch (Throwable) {
            // Safe rollback for databases where the index was already removed manually.
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        try {
            if (!method_exists(Schema::getFacadeRoot(), 'getIndexes')) {
                return false;
            }

            foreach (Schema::getIndexes($table) as $index) {
                if (($index['name'] ?? null) === $name) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};
