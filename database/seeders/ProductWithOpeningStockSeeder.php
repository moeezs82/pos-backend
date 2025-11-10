<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use Illuminate\Support\Arr;

class ProductWithOpeningStockSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // How many products to create
        $count = 10;

        // Use factory to create skeleton products, then run same stock/accounting logic
        \App\Models\Product::factory()->count($count)->make()->each(function ($p) {
            DB::transaction(function () use ($p) {
                // Save product
                $data = $p->toArray();
                // Ensure unique fields are null or unique if collision
                $data['sku'] = $data['sku'] ?: null;
                $data['barcode'] = $data['barcode'] ?: null;

                $product = Product::create(Arr::only($data, [
                    'sku',
                    'barcode',
                    'name',
                    'description',
                    'category_id',
                    'vendor_id',
                    'brand_id',
                    'price',
                    'cost_price',
                    'wholesale_price',
                    // 'stock',
                    // 'tax_rate',
                    // 'tax_inclusive',
                    'discount',
                    'is_active'
                ]));

                // Simulate opening stock: random qty
                $qty = rand(0, 50);
                if ($qty > 0) {
                    $unitCost = (float) ($product->cost_price ?? 0);
                    $branchId = null; // change if you want to seed per-branch stocks
                    $asOf = now()->toDateString();

                    // 1) Update stocks with moving-average (opening)
                    app(\App\Services\InventoryValuationWriteService::class)->receivePurchase(
                        productId: $product->id,
                        branchId: $branchId,
                        receiveQty: $qty,
                        unitPrice: $unitCost,
                        ref: 'OPENING'
                    );

                    // 2) Accumulate branch value for GL posting
                    $value = round($qty * $unitCost, 2);

                    // 3) Post GL JE per branch: DR Inventory (1400) / CR Opening Equity (3100)
                    if ($value > 0) {
                        app(\App\Services\AccountingService::class)->post(
                            branchId: $branchId,
                            memo: "Opening stock for {$product->name} (#{$product->id})",
                            reference: $product,
                            lines: [
                                ['account_code' => '1400', 'debit' => $value, 'credit' => 0],
                                ['account_code' => '3100', 'debit' => 0,      'credit' => $value],
                            ],
                            entryDate: $asOf,
                            userId: 1 // if seeding from CLI, this may be null; set to a user id if needed
                        );
                    }
                }

                // Reload relations if you want
                $product->load('category', 'brand', 'stocks');

                // Optionally output to console
                $this->command->info("Seeded product: {$product->id} - {$product->name} (opening qty: {$qty})");
            });
        });
    }
}
