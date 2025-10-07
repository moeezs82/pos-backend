<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InventoryValuationService
{
    // Returns current average cost per unit for a product at a branch.
    public function avgCost(int $productId, int $branchId): float
    {
        // Replace with your own valuation logic if you store purchases with cost.
        $row = DB::table('product_stocks')
            ->where('product_id', $productId)->where('branch_id', $branchId)
            ->select('avg_cost')->first(); // add avg_cost column in your stocks if not present
        return (float)($row->avg_cost ?? 0);
    }
}
