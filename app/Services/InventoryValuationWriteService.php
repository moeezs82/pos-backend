<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InventoryValuationWriteService
{
    /**
     * Receives purchase into product_stocks and updates avg_cost (moving average).
     */
    public function receivePurchase(int $productId, int $branchId, int $receiveQty, float $unitPrice, ?string $ref = null): void
    {
        if ($receiveQty <= 0) return;

        $row = DB::table('product_stocks')
            ->lockForUpdate()
            ->where('product_id', $productId)
            ->where('branch_id',  $branchId)
            ->first();

        $oldQty  = (int)($row->quantity ?? 0);
        $oldCost = (float)($row->avg_cost ?? 0.0);

        $newQty  = $oldQty + $receiveQty;

        // moving average: ((oldQty*oldCost)+(receiveQty*unitPrice))/newQty
        $newAvg = $newQty > 0
            ? round((($oldQty * $oldCost) + ($receiveQty * $unitPrice)) / $newQty, 4)
            : $unitPrice;

        // Update stocks
        DB::table('product_stocks')->updateOrInsert(
            ['product_id' => $productId, 'branch_id' => $branchId],
            ['quantity' => $newQty, 'avg_cost' => $newAvg, 'updated_at' => now(), 'created_at' => $row->created_at ?? now()]
        );

        // Movement (inbound)
        DB::table('stock_movements')->insert([
            'product_id' => $productId,
            'branch_id'  => $branchId,
            'type'       => 'purchase',
            'quantity'   => $receiveQty,
            'reference'  => $ref,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function returnToVendor(
        int $productId,
        int $branchId,
        int $returnQty,
        ?string $ref = null
    ): float {
        // Expect positive qty for a return. If 0 or negative, just echo current avg and do nothing.
        if ($returnQty <= 0) {
            return (float) DB::table('product_stocks')
                ->where('product_id', $productId)
                ->where('branch_id',  $branchId)
                ->value('avg_cost') ?? 0.0;
        }

        // Lock/create stock row
        $row = DB::table('product_stocks')
            ->lockForUpdate()
            ->where('product_id', $productId)
            ->where('branch_id',  $branchId)
            ->first();

        if (!$row) {
            DB::table('product_stocks')->insert([
                'product_id' => $productId,
                'branch_id'  => $branchId,
                'quantity'   => 0,
                'avg_cost'   => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = (object)['quantity' => 0, 'avg_cost' => 0];
        }

        $avg    = (float) ($row->avg_cost ?? 0.0);
        $onHand = (int)   ($row->quantity ?? 0);

        // NO CLAMPING: allow going negative
        $newQty = $onHand - $returnQty;

        DB::table('product_stocks')
            ->where('product_id', $productId)
            ->where('branch_id',  $branchId)
            ->update([
                'quantity'   => $newQty,
                // moving-average issue: avg_cost stays unchanged on outbound
                'updated_at' => now(),
            ]);

        DB::table('stock_movements')->insert([
            'product_id' => $productId,
            'branch_id'  => $branchId,
            'type'       => 'purchase_return',
            'quantity'   => -$returnQty, // outbound is negative
            'reference'  => $ref,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $avg; // caller can use this for PPV vs AP
    }
}
