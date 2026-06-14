<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        $this->addBranchColumnToProducts();
        $this->dropProductsSkuGlobalUniqueIndex();
        $this->splitExistingProductsByBranch();
        $this->createProductBranchIndexes();
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        $this->dropIndexIfExists('products_branch_sku_unique');
        $this->dropIndexIfExists('products_branch_barcode_idx');
        $this->dropIndexIfExists('products_branch_name_idx');

        if (Schema::hasColumn('products', 'branch_id')) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('ALTER TABLE "products" DROP COLUMN "branch_id"');
            } else {
                Schema::table('products', function (Blueprint $table) {
                    $table->dropConstrainedForeignId('branch_id');
                });
            }
        }

        $this->createProductsSkuGlobalUniqueIndex();
    }

    private function addBranchColumnToProducts(): void
    {
        if (Schema::hasColumn('products', 'branch_id')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE "products" ADD COLUMN "branch_id" INTEGER NULL');
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('branches')
                ->nullOnDelete();
        });
    }

    private function splitExistingProductsByBranch(): void
    {
        $defaultBranchId = DB::table('branches')->orderBy('id')->value('id');
        if (!$defaultBranchId) {
            return;
        }

        $columns = collect(Schema::getColumnListing('products'))
            ->reject(fn ($column) => $column === 'id')
            ->values()
            ->all();

        DB::table('products')
            ->orderBy('id')
            ->chunkById(50, function ($products) use ($defaultBranchId, $columns) {
                foreach ($products as $product) {
                    $branches = $this->branchesUsedByProduct((int) $product->id);
                    if ($branches->isEmpty()) {
                        $branches = collect([(int) $defaultBranchId]);
                    }

                    $primaryBranchId = (int) $branches->first();
                    DB::table('products')
                        ->where('id', $product->id)
                        ->update(['branch_id' => $primaryBranchId]);

                    foreach ($branches->slice(1) as $branchId) {
                        $branchId = (int) $branchId;
                        $newProductId = $this->cloneProductForBranch($product, $columns, $branchId);
                        $this->remapBranchProductReferences((int) $product->id, $newProductId, $branchId);
                    }
                }
            });
    }

    private function branchesUsedByProduct(int $productId): \Illuminate\Support\Collection
    {
        $branchIds = collect();

        $branchIds = $branchIds->merge(
            DB::table('product_stocks')->where('product_id', $productId)->whereNotNull('branch_id')->pluck('branch_id')
        );

        if (Schema::hasTable('stock_movements')) {
            $branchIds = $branchIds->merge(
                DB::table('stock_movements')->where('product_id', $productId)->whereNotNull('branch_id')->pluck('branch_id')
            );
        }

        if (Schema::hasTable('sale_items') && Schema::hasTable('sales')) {
            $branchIds = $branchIds->merge(
                DB::table('sale_items as si')
                    ->join('sales as s', 's.id', '=', 'si.sale_id')
                    ->where('si.product_id', $productId)
                    ->whereNotNull('s.branch_id')
                    ->pluck('s.branch_id')
            );
        }

        if (Schema::hasTable('purchase_items') && Schema::hasTable('purchases')) {
            $branchIds = $branchIds->merge(
                DB::table('purchase_items as pi')
                    ->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
                    ->where('pi.product_id', $productId)
                    ->whereNotNull('p.branch_id')
                    ->pluck('p.branch_id')
            );
        }

        if (Schema::hasTable('sale_return_items') && Schema::hasTable('sale_returns')) {
            $branchIds = $branchIds->merge(
                DB::table('sale_return_items as sri')
                    ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
                    ->where('sri.product_id', $productId)
                    ->whereNotNull('sr.branch_id')
                    ->pluck('sr.branch_id')
            );
        }

        if (Schema::hasTable('purchase_claim_items') && Schema::hasTable('purchase_claims')) {
            $branchIds = $branchIds->merge(
                DB::table('purchase_claim_items as pci')
                    ->join('purchase_claims as pc', 'pc.id', '=', 'pci.purchase_claim_id')
                    ->where('pci.product_id', $productId)
                    ->whereNotNull('pc.branch_id')
                    ->pluck('pc.branch_id')
            );
        }

        return $branchIds
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort()
            ->values();
    }

    private function cloneProductForBranch(object $product, array $columns, int $branchId): int
    {
        $row = [];
        foreach ($columns as $column) {
            $row[$column] = $column === 'branch_id' ? $branchId : ($product->{$column} ?? null);
        }

        return (int) DB::table('products')->insertGetId($row);
    }

    private function remapBranchProductReferences(int $oldProductId, int $newProductId, int $branchId): void
    {
        DB::table('product_stocks')
            ->where('product_id', $oldProductId)
            ->where('branch_id', $branchId)
            ->update(['product_id' => $newProductId]);

        if (Schema::hasTable('stock_movements')) {
            DB::table('stock_movements')
                ->where('product_id', $oldProductId)
                ->where('branch_id', $branchId)
                ->update(['product_id' => $newProductId]);
        }

        if (Schema::hasTable('sale_items') && Schema::hasTable('sales')) {
            DB::table('sale_items')
                ->where('product_id', $oldProductId)
                ->whereIn('sale_id', DB::table('sales')->select('id')->where('branch_id', $branchId))
                ->update(['product_id' => $newProductId]);
        }

        if (Schema::hasTable('purchase_items') && Schema::hasTable('purchases')) {
            DB::table('purchase_items')
                ->where('product_id', $oldProductId)
                ->whereIn('purchase_id', DB::table('purchases')->select('id')->where('branch_id', $branchId))
                ->update(['product_id' => $newProductId]);
        }

        if (Schema::hasTable('sale_return_items') && Schema::hasTable('sale_returns')) {
            DB::table('sale_return_items')
                ->where('product_id', $oldProductId)
                ->whereIn('sale_return_id', DB::table('sale_returns')->select('id')->where('branch_id', $branchId))
                ->update(['product_id' => $newProductId]);
        }

        if (Schema::hasTable('purchase_claim_items') && Schema::hasTable('purchase_claims')) {
            DB::table('purchase_claim_items')
                ->where('product_id', $oldProductId)
                ->whereIn('purchase_claim_id', DB::table('purchase_claims')->select('id')->where('branch_id', $branchId))
                ->update(['product_id' => $newProductId]);
        }

        if (Schema::hasTable('journal_entries')) {
            DB::table('journal_entries')
                ->where('reference_type', \App\Models\Product::class)
                ->where('reference_id', $oldProductId)
                ->where('branch_id', $branchId)
                ->update(['reference_id' => $newProductId]);
        }
    }

    private function createProductBranchIndexes(): void
    {
        $this->createIndexIfMissing('products_branch_name_idx', ['branch_id', 'name']);
        $this->createIndexIfMissing('products_branch_barcode_idx', ['branch_id', 'barcode']);
        $this->createUniqueIndexIfMissing('products_branch_sku_unique', ['branch_id', 'sku']);
    }

    private function dropProductsSkuGlobalUniqueIndex(): void
    {
        $this->dropIndexIfExists('products_sku_unique');
    }

    private function createProductsSkuGlobalUniqueIndex(): void
    {
        // Do not recreate the old global SKU unique index if branch-scoped duplicates exist.
        $duplicates = DB::table('products')
            ->select('sku')
            ->groupBy('sku')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (!$duplicates) {
            $this->createUniqueIndexIfMissing('products_sku_unique', ['sku']);
        }
    }

    private function createIndexIfMissing(string $name, array $columns): void
    {
        if ($this->indexExists($name)) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            try {
                DB::statement(sprintf(
                    'CREATE INDEX IF NOT EXISTS "%s" ON "products" (%s)',
                    str_replace('"', '""', $name),
                    $this->quotedColumns($columns)
                ));
            } catch (Throwable) {
            }
            return;
        }

        try {
            Schema::table('products', fn (Blueprint $table) => $table->index($columns, $name));
        } catch (Throwable) {
        }
    }

    private function createUniqueIndexIfMissing(string $name, array $columns): void
    {
        if ($this->indexExists($name)) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            try {
                DB::statement(sprintf(
                    'CREATE UNIQUE INDEX IF NOT EXISTS "%s" ON "products" (%s)',
                    str_replace('"', '""', $name),
                    $this->quotedColumns($columns)
                ));
            } catch (Throwable) {
                $this->createIndexIfMissing(str_replace('_unique', '_idx', $name), $columns);
            }
            return;
        }

        try {
            Schema::table('products', fn (Blueprint $table) => $table->unique($columns, $name));
        } catch (Throwable) {
        }
    }

    private function dropIndexIfExists(string $name): void
    {
        if (!$this->indexExists($name)) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(sprintf('DROP INDEX IF EXISTS "%s"', str_replace('"', '""', $name)));
            return;
        }

        try {
            Schema::table('products', fn (Blueprint $table) => $table->dropIndex($name));
        } catch (Throwable) {
            try {
                Schema::table('products', fn (Blueprint $table) => $table->dropUnique($name));
            } catch (Throwable) {
            }
        }
    }

    private function quotedColumns(array $columns): string
    {
        return collect($columns)
            ->map(fn ($column) => '"' . str_replace('"', '""', $column) . '"')
            ->implode(', ');
    }

    private function indexExists(string $name): bool
    {
        try {
            if (!method_exists(Schema::getFacadeRoot(), 'getIndexes')) {
                return false;
            }

            foreach (Schema::getIndexes('products') as $index) {
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
