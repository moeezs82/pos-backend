<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStock;

class InventoryValuationService
{
    /**
     * Return the current average cost per unit for a product at a branch.
     *
     * If the stock record does not exist, create it using the product's
     * current cost price as the initial average cost.
     */
    public function avgCost(int $productId, ?int $branchId): float
    {
        $stock = ProductStock::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->first();

        if (!$stock) {
            $product = Product::query()
                ->select(['id', 'cost_price'])
                ->findOrFail($productId);

            $stock = ProductStock::query()->firstOrCreate(
                [
                    'product_id' => $productId,
                    'branch_id' => $branchId,
                ],
                [
                    'quantity' => 0,
                    'avg_cost' => (float) ($product->cost_price ?? 0),
                ]
            );
        }

        return (float) $stock->avg_cost;
    }
}