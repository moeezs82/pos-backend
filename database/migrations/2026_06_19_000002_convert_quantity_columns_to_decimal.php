<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Converts every quantity-bearing column from INTEGER to DECIMAL so sales
 * and purchases can use fractional quantities (e.g. 1.5 kg, 0.25 L).
 *
 * Implemented with raw ALTER TABLE statements (not Schema::table()->change())
 * so this does NOT require doctrine/dbal to be installed.
 *
 * SQLite note: SQLite uses type *affinity*, not strict typing — a column
 * declared INTEGER will already silently store a fractional value as REAL
 * if you write one into it (it does not truncate). So on SQLite this
 * migration is a no-op load-bearing-wise; we skip the ALTER entirely there
 * since SQLite's ALTER TABLE can't MODIFY a column type without a full
 * table rebuild, and it isn't needed for correctness.
 */
return new class extends Migration
{
    /** Table => [column => decimal precision,scale] to convert. */
    private array $columns = [
        'product_stocks'       => ['quantity' => [14, 3]],
        'stock_movements'      => ['quantity' => [14, 3]],
        'sale_items'           => ['quantity' => [14, 3]],
        'sale_return_items'    => ['quantity' => [14, 3]],
        'purchase_items'       => ['quantity' => [14, 3], 'received_qty' => [14, 3]],
        'purchase_claim_items' => ['quantity' => [14, 3]],
        'products'             => ['stock_qty' => [14, 3], 'reorder_level' => [14, 3]],
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // No-op: see class docblock. SQLite already stores decimals fine
            // in these columns thanks to type affinity rules.
            return;
        }

        foreach ($this->columns as $table => $cols) {
            if (!$this->tableExists($table)) {
                continue;
            }

            foreach ($cols as $column => [$precision, $scale]) {
                if (!$this->columnExists($table, $column)) {
                    continue;
                }
                $this->alterColumnToDecimal($driver, $table, $column, $precision, $scale);
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        // Revert to INTEGER. Any fractional data inserted while this
        // migration was active will be truncated on rollback — that's
        // expected for a down() migration reverting a type widening.
        $intCols = [
            'product_stocks'       => ['quantity'],
            'stock_movements'      => ['quantity'],
            'sale_items'           => ['quantity'],
            'sale_return_items'    => ['quantity'],
            'purchase_items'       => ['quantity', 'received_qty'],
            'purchase_claim_items' => ['quantity'],
            'products'             => ['stock_qty', 'reorder_level'],
        ];

        foreach ($intCols as $table => $cols) {
            if (!$this->tableExists($table)) {
                continue;
            }
            foreach ($cols as $column) {
                if (!$this->columnExists($table, $column)) {
                    continue;
                }
                $this->alterColumnToInteger($driver, $table, $column);
            }
        }
    }

    private function tableExists(string $table): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable($table);
    }

    private function columnExists(string $table, string $column): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
    }

    private function alterColumnToDecimal(string $driver, string $table, string $column, int $precision, int $scale): void
    {
        if ($driver === 'mysql') {
            // Preserve NOT NULL / default where they previously existed by
            // just widening the numeric type — MySQL keeps existing
            // nullability/defaults when you MODIFY without restating them
            // only if you DO restate them, so we restate sane defaults here.
            $default = in_array($column, ['quantity', 'stock_qty', 'reorder_level', 'received_qty'], true) ? ' DEFAULT 0' : '';
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` DECIMAL({$precision},{$scale}) NOT NULL{$default}");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE NUMERIC({$precision},{$scale})");
            DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" SET DEFAULT 0");
        }
        // sqlserver / others: extend here if you target them.
    }

    private function alterColumnToInteger(string $driver, string $table, string $column): void
    {
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` INT NOT NULL DEFAULT 0");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE INTEGER USING ROUND(\"{$column}\")::integer");
            DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" SET DEFAULT 0");
        }
    }
};
