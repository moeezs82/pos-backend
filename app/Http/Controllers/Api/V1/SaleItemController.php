<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleItemController extends Controller
{
    // ADD item to sale
    public function store(Request $request, $saleId)
    {
        $sale = Sale::with(['items', 'payments'])->findOrFail($saleId);

        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1',
            'price'      => 'required|numeric|min:0',
        ]);

        $branchId = $sale->branch_id;

        return DB::transaction(function () use ($sale, $data, $branchId) {
            // snapshot old totals & cogs for adjustments
            $oldTotals = [
                'subtotal' => (float)$sale->subtotal,
                'discount' => (float)$sale->discount,
                'tax'      => (float)$sale->tax,
                'total'    => (float)$sale->total,
            ];
            $oldCogs = (float)$sale->cogs;

            // create item
            $item = $sale->items()->create([
                'product_id' => $data['product_id'],
                'quantity'   => (int)$data['quantity'],
                'price'      => (float)$data['price'],
                'total'      => round($data['quantity'] * $data['price'], 2),
            ]);

            // compute unit cost using inventory valuation (avg cost)
            $valService = app(\App\Services\InventoryValuationService::class);
            $avgCost = (float)$valService->avgCost($item->product_id, $branchId);
            $unitCost = round($avgCost, 4); // keep a few decimals for unit cost
            $lineCost = round($unitCost * $item->quantity, 2);

            // update item with cost
            $item->update([
                'unit_cost' => $unitCost,
                'line_cost' => $lineCost,
            ]);

            // adjust stock: decrement by quantity (selling more)
            DB::table('product_stocks')
                ->where('product_id', $item->product_id)
                ->where('branch_id', $branchId)
                ->decrement('quantity', $item->quantity);

            // stock movement record
            StockMovement::create([
                'product_id' => $item->product_id,
                'branch_id'  => $branchId,
                'type'       => 'sale',
                'quantity'   => -$item->quantity,
                'reference'  => $sale->invoice_no,
            ]);

            // update sale.cogs and gross_profit
            $newCogs = round($oldCogs + $lineCost, 2);
            $sale->update([
                'cogs' => $newCogs,
                'gross_profit' => round($sale->total - $newCogs, 2),
            ]);

            // recalc totals (subtotal/total) including new item
            $this->recalculateSale($sale);

            // Post sales totals adjustment (AR/Revenue/Tax) based on totals delta
            app(\App\Services\SaleAdjustmentService::class)->postSaleAdjustment(
                $sale->fresh(['items']),
                $oldTotals,
                [
                    'subtotal' => (float) round($sale->subtotal, 2),
                    'discount' => (float) round($sale->discount, 2),
                    'tax'      => (float) round($sale->tax, 2),
                    'total'    => (float) round($sale->total, 2),
                ],
                now()->toDateString()
            );

            // Post COGS Inventory JE for the additional COGS (debit COGS, credit Inventory)
            $cogsDelta = round($lineCost, 2);
            if ($cogsDelta != 0.0) {
                $acc = app(\App\Services\AccountingService::class);
                $acc->post(
                    branchId: $sale->branch_id,
                    memo: "Sale #{$sale->invoice_no} - COGS for added item (product {$item->product_id})",
                    reference: $sale,
                    lines: [
                        ['account_code' => '5100', 'debit' => $cogsDelta, 'credit' => 0], // COGS
                        ['account_code' => '1400', 'debit' => 0,        'credit' => $cogsDelta], // Inventory
                    ],
                    entryDate: now()->toDateString(),
                    userId: auth()->id()
                );
            }

            return ApiResponse::success(['item' => $item->fresh()], 'Item added successfully');
        });
    }

    // EDIT existing item (quantity/price)
    public function update(Request $request, $saleId, $itemId)
    {
        $sale = Sale::with(['items', 'payments'])->findOrFail($saleId);
        $item = $sale->items()->findOrFail($itemId);

        $data = $request->validate([
            'quantity' => 'sometimes|integer|min:1',
            'price'    => 'sometimes|numeric|min:0',
        ]);

        $branchId = $sale->branch_id;

        return DB::transaction(function () use ($sale, $item, $data, $branchId) {
            // snapshot old totals & cogs
            $oldTotals = [
                'subtotal' => (float)$sale->subtotal,
                'discount' => (float)$sale->discount,
                'tax'      => (float)$sale->tax,
                'total'    => (float)$sale->total,
            ];
            $oldCogs = (float)$sale->cogs;

            $oldQty = (int)$item->quantity;
            $oldPrice = (float)$item->price;
            $oldLineCost = (float)($item->line_cost ?? 0);

            $newQty = array_key_exists('quantity', $data) ? (int)$data['quantity'] : $oldQty;
            $newPrice = array_key_exists('price', $data) ? (float)$data['price'] : $oldPrice;

            // fetch avg cost to use for unit_cost (we keep item's unit_cost aligned to avg)
            $valService = app(\App\Services\InventoryValuationService::class);
            $avgCost = (float)$valService->avgCost($item->product_id, $branchId);
            $unitCost = round($avgCost, 4);
            $newLineCost = round($unitCost * $newQty, 2);

            // update item row
            $item->update([
                'quantity'  => $newQty,
                'price'     => $newPrice,
                'total'     => round($newQty * $newPrice, 2),
                'unit_cost' => $unitCost,
                'line_cost' => $newLineCost,
            ]);

            // compute stock delta and apply
            $qtyDelta = $newQty - $oldQty; // positive => sold more, negative => sold less (return to stock)
            if ($qtyDelta !== 0) {
                // applyStockDelta expects deltaQty positive to add to stock; we want to decrement stock when selling more
                // so pass -$qtyDelta to apply the correct sign for product_stocks and StockMovement
                DB::table('product_stocks')
                    ->where('product_id', $item->product_id)
                    ->where('branch_id', $branchId)
                    ->update(['quantity' => DB::raw("quantity - ({$qtyDelta})")]); // subtract qtyDelta (pos->dec, neg->inc)

                StockMovement::create([
                    'product_id' => $item->product_id,
                    'branch_id'  => $branchId,
                    'type'       => 'adjustment',
                    'quantity'   => -$qtyDelta, // negative when sold more, positive when returned
                    'reference'  => $sale->invoice_no,
                ]);
            }

            // update sale.cogs (apply cogs delta)
            $cogsDelta = round($newLineCost - $oldLineCost, 2);
            $newCogs = round($oldCogs + $cogsDelta, 2);
            $sale->update([
                'cogs' => $newCogs,
                'gross_profit' => round($sale->total - $newCogs, 2),
            ]);

            // Recalc totals
            $this->recalculateSale($sale);

            // Post sales totals adjustment for revenue/tax/AR
            app(\App\Services\SaleAdjustmentService::class)->postSaleAdjustment(
                $sale->fresh(['items']),
                $oldTotals,
                [
                    'subtotal' => (float) round($sale->subtotal, 2),
                    'discount' => (float) round($sale->discount, 2),
                    'tax'      => (float) round($sale->tax, 2),
                    'total'    => (float) round($sale->total, 2),
                ],
                now()->toDateString()
            );

            // Post COGS / Inventory JE for cogsDelta (sign aware)
            if (round($cogsDelta, 2) !== 0.0) {
                $acc = app(\App\Services\AccountingService::class);
                if ($cogsDelta > 0) {
                    // Sold more COGS -> DR COGS, CR Inventory
                    $lines = [
                        ['account_code' => '5100', 'debit' => $cogsDelta, 'credit' => 0],
                        ['account_code' => '1400', 'debit' => 0,        'credit' => $cogsDelta],
                    ];
                    $memo = "Sale #{$sale->invoice_no} - additional COGS for item #{$item->id}";
                } else {
                    // cogsDelta < 0 => items decreased -> reverse COGS: DR Inventory, CR COGS
                    $amt = abs($cogsDelta);
                    $lines = [
                        ['account_code' => '1400', 'debit' => $amt, 'credit' => 0],
                        ['account_code' => '5100', 'debit' => 0,    'credit' => $amt],
                    ];
                    $memo = "Sale #{$sale->invoice_no} - reversed COGS for item #{$item->id}";
                }

                $acc->post(
                    branchId: $sale->branch_id,
                    memo: $memo,
                    reference: $sale,
                    lines: $lines,
                    entryDate: now()->toDateString(),
                    userId: auth()->id()
                );
            }

            return ApiResponse::success(['item' => $item->fresh()], 'Item updated successfully');
        });
    }

    // DELETE item (return quantity to stock)
    public function destroy($saleId, $itemId)
    {
        $sale = Sale::with(['items', 'payments'])->findOrFail($saleId);
        $item = $sale->items()->findOrFail($itemId);

        $branchId = $sale->branch_id;

        return DB::transaction(function () use ($sale, $item, $branchId) {
            // snapshot totals & cogs
            $oldTotals = [
                'subtotal' => (float)$sale->subtotal,
                'discount' => (float)$sale->discount,
                'tax'      => (float)$sale->tax,
                'total'    => (float)$sale->total,
            ];
            $oldCogs = (float)$sale->cogs;

            $qty = (int)$item->quantity;
            $lineCost = (float)($item->line_cost ?? 0);

            // Return stock for removed sale quantity (put back into product_stocks)
            DB::table('product_stocks')
                ->where('product_id', $item->product_id)
                ->where('branch_id', $branchId)
                ->increment('quantity', $qty);

            StockMovement::create([
                'product_id' => $item->product_id,
                'branch_id'  => $branchId,
                'type'       => 'adjustment',
                'quantity'   => $qty, // positive => in
                'reference'  => $sale->invoice_no,
            ]);

            // Remove item
            $item->delete();

            // Update sale.cogs (subtract lineCost) and gross_profit
            $newCogs = round(max(0, $oldCogs - $lineCost), 2);
            $sale->update([
                'cogs' => $newCogs,
                'gross_profit' => round($sale->total - $newCogs, 2),
            ]);

            // Recalculate totals (subtotal & total)
            $this->recalculateSale($sale);

            // Post sales totals adjustment (AR/Revenue/Tax)
            app(\App\Services\SaleAdjustmentService::class)->postSaleAdjustment(
                $sale->fresh(['items']),
                $oldTotals,
                [
                    'subtotal' => (float) round($sale->subtotal, 2),
                    'discount' => (float) round($sale->discount, 2),
                    'tax'      => (float) round($sale->tax, 2),
                    'total'    => (float) round($sale->total, 2),
                ],
                now()->toDateString()
            );

            // Post COGS reversal JE (we're returning goods -> DR Inventory, CR COGS)
            if ($lineCost > 0) {
                $acc = app(\App\Services\AccountingService::class);
                $acc->post(
                    branchId: $sale->branch_id,
                    memo: "Sale #{$sale->invoice_no} - COGS reversal for removed item (product {$item->product_id})",
                    reference: $sale,
                    lines: [
                        ['account_code' => '1400', 'debit' => $lineCost, 'credit' => 0], // Inventory
                        ['account_code' => '5100', 'debit' => 0,        'credit' => $lineCost], // COGS
                    ],
                    entryDate: now()->toDateString(),
                    userId: auth()->id()
                );
            }

            return ApiResponse::success(null, 'Item deleted successfully');
        });
    }

    /* ---------- Helpers ---------- */

    private function recalculateSale(Sale $sale): void
    {
        // recompute subtotal and total (discount & tax are assumed on sale header)
        $subtotal = (float) $sale->items()->sum(DB::raw('quantity * price'));
        $discount = (float) ($sale->discount ?? 0);
        $tax      = (float) ($sale->tax ?? 0);
        $total    = round(max(0, $subtotal - $discount + $tax), 2);

        $sale->update([
            'subtotal' => round($subtotal, 2),
            'total'    => $total,
        ]);

        // status after payments
        $paid = (float) $sale->payments()->sum('amount');
        if ($paid >= $total && $total > 0) {
            $sale->update(['status' => 'paid']);
        } elseif ($paid > 0) {
            $sale->update(['status' => 'partial']);
        } else {
            $sale->update(['status' => 'pending']);
        }

        // update gross_profit if cogs exists
        $cogs = (float) $sale->cogs;
        $sale->update(['gross_profit' => round($sale->total - $cogs, 2)]);
    }
}
