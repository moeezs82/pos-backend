<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleItemController extends Controller
{
    // ADD item to sale
    public function store(Request $request, $saleId)
    {
        $sale = Sale::with('items')->findOrFail($saleId);

        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1',
            'price'      => 'required|numeric|min:0',
        ]);

        $branchId = $sale->branch_id;

        return DB::transaction(function () use ($sale, $data, $branchId) {
            $item = $sale->items()->create([
                'product_id' => $data['product_id'],
                'quantity'   => $data['quantity'],
                'price'      => $data['price'],
                'total'      => $data['quantity'] * $data['price'],
            ]);

            // Deduct stock for new sale quantity
            $this->applyStockDelta($data['product_id'], $branchId, -$data['quantity'], $sale->invoice_no);

            // Recalculate totals + status
            $this->recalculateSale($sale);

            return ApiResponse::success(
                ['item' => $item],
                'Item added successfully',
            );
        });
    }

    // EDIT existing item (quantity/price)
    public function update(Request $request, $saleId, $itemId)
    {
        $sale = Sale::with('items')->findOrFail($saleId);
        $item = $sale->items()->findOrFail($itemId);

        $data = $request->validate([
            'quantity' => 'sometimes|integer|min:1',
            'price'    => 'sometimes|numeric|min:0',
        ]);

        $branchId = $sale->branch_id;

        return DB::transaction(function () use ($sale, $item, $data, $branchId) {
            $oldQty = (int)$item->quantity;
            $newQty = (int)($data['quantity'] ?? $oldQty);

            $oldPrice = (float)$item->price;
            $newPrice = (float)($data['price'] ?? $oldPrice);

            // Update item row
            $item->update([
                'quantity' => $newQty,
                'price'    => $newPrice,
                'total'    => $newQty * $newPrice,
            ]);

            // Stock delta = newQty - oldQty (for sale: positive means we sold more → decrement stock)
            $delta = $newQty - $oldQty;
            if ($delta !== 0) {
                // for sale, selling more -> negative movement; selling less -> positive (return to stock)
                $this->applyStockDelta($item->product_id, $branchId, -$delta, $sale->invoice_no, 'adjustment');
            }

            $this->recalculateSale($sale);

            return ApiResponse::success(
                ['item' => $item->fresh()],
                'Item updated successfully',
            );
        });
    }

    // DELETE item (return quantity to stock)
    public function destroy($saleId, $itemId)
    {
        $sale = Sale::with('items')->findOrFail($saleId);
        $item = $sale->items()->findOrFail($itemId);

        $branchId = $sale->branch_id;

        return DB::transaction(function () use ($sale, $item, $branchId) {
            $qty = (int)$item->quantity;
            $productId = $item->product_id;

            // Return stock for removed sale quantity
            $this->applyStockDelta($productId, $branchId, +$qty, $sale->invoice_no, 'adjustment');

            $item->delete();

            $this->recalculateSale($sale);

            return ApiResponse::success(
                null,
                'Item deleted successfully'
            );
        });
    }

    /* ---------- Helpers ---------- */

    private function applyStockDelta(int $productId, int $branchId, int $deltaQty, string $reference, string $type = 'sale'): void
    {
        if ($deltaQty === 0) return;

        // Update branch stock
        DB::table('product_stocks')
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->update([
                'quantity' => DB::raw("quantity + ($deltaQty)")
            ]);

        // Movement (use 'sale' for original creating; 'adjustment' for edits)
        StockMovement::create([
            'product_id' => $productId,
            'branch_id'  => $branchId,
            'type'       => $type,                 // 'sale' or 'adjustment'
            'quantity'   => $deltaQty,             // negative means out, positive means in
            'reference'  => $reference,
        ]);
    }

    private function recalculateSale(Sale $sale): void
    {
        $subtotal = (float) $sale->items()->sum(DB::raw('quantity * price'));
        $discount = (float) ($sale->discount ?? 0);
        $tax      = (float) ($sale->tax ?? 0);
        $total    = $subtotal - $discount + $tax;

        $sale->update([
            'subtotal' => $subtotal,
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
    }
}
