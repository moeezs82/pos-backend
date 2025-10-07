<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaleController extends Controller
{
    // List all sales
    public function index(Request $request)
    {
        $query = Sale::with(['customer', 'branch'])
            ->withSum('payments as paid_amount', 'amount');

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_no', 'like', "%$search%")
                    ->orWhereHas('customer', function ($c) use ($search) {
                        $c->where('first_name', 'like', "%$search%")
                            ->orWhere('last_name', 'like', "%$search%")
                            ->orWhere('email', 'like', "%$search%")
                            ->orWhere('phone', 'like', "%$search%");
                    });
            });
        }

        if ($request->sort_by == 'total') {
            $query->orderBy('total', 'desc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $sales = $query->paginate(15);

        return ApiResponse::success($sales);
    }

    // Get single sale with details
    public function show($id)
    {
        $sale = Sale::with(['customer:id,first_name,last_name', 'branch', 'items.product:id,name', 'payments', 'vendor:id,first_name,last_name', 'salesman:id,name'])->findOrFail($id);

        return ApiResponse::success($sale);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'vendor_id'   => 'nullable|exists:vendors,id',
            'salesman_id' => 'nullable|exists:users,id',
            'created_by'  => 'nullable|exists:users,id',
            'branch_id'   => 'required|exists:branches,id',
            'items'       => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.price'      => 'required|numeric|min:0',
            'discount'    => 'nullable|numeric|min:0',
            'tax'         => 'nullable|numeric|min:0',
            'payments'    => 'array', // optional receipts info
        ]);

        $branchId = $data['branch_id'];

        return DB::transaction(function () use ($data, $branchId) {
            // totals
            $subtotal = collect($data['items'])->sum(fn($i) => $i['quantity'] * $i['price']);
            $discount = (float)($data['discount'] ?? 0);
            $tax      = (float)($data['tax'] ?? 0);
            $total    = max(0, round($subtotal - $discount + $tax, 2));

            // create sale header
            $sale = Sale::create([
                'invoice_no'  => 'INV-' . time(),
                'customer_id' => $data['customer_id'] ?? null,
                'vendor_id'   => $data['vendor_id'] ?? null,
                'salesman_id' => $data['salesman_id'] ?? null,
                'created_by'  => $data['created_by'] ?? auth()->id(),
                'branch_id'   => $branchId,
                'subtotal'    => round($subtotal, 2),
                'discount'    => round($discount, 2),
                'tax'         => round($tax, 2),
                'total'       => $total,
                'status'      => 'pending',
            ]);

            // create items (do not duplicate stock decrement here — handled by deductStockAndStampCosts)
            foreach ($data['items'] as $item) {
                $sale->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity'   => (int)$item['quantity'],
                    'price'      => (float)$item['price'],
                    'total'      => round($item['quantity'] * $item['price'], 2),
                    // unit_cost/line_cost will be set by deductStockAndStampCosts
                ]);
            }

            // Deduct stock, stamp costs (sets unit_cost & line_cost on items) and update sale.cogs/gross_profit
            // This method uses InventoryValuationService->avgCost(...) and writes unit_cost/line_cost, product_stocks, stock movements
            app(\App\Services\SalePostingService::class)->deductStockAndStampCosts($sale->fresh('items'));

            // Post Sales JE (AR / Revenue / Tax / COGS / Inventory)
            app(\App\Services\SalePostingService::class)->postSale($sale->fresh('items'));

            // Optional immediate receipts/payments
            $createdReceipts = [];
            foreach (($data['payments'] ?? []) as $payment) {
                // auto-allocate to this sale if allocations missing
                $allocations = $payment['allocations'] ?? null;
                if (empty($allocations)) {
                    $allocations = [
                        ['sale_id' => $sale->id, 'amount' => min((float)$payment['amount'], (float)$sale->total)]
                    ];
                }

                $receiptPayload = [
                    'customer_id' => $sale->customer_id,
                    'branch_id'   => $sale->branch_id,
                    'received_at' => $payment['paid_at'] ?? now()->toDateString(),
                    'method'      => $payment['method'] ?? 'cash',
                    'amount'      => (float)$payment['amount'],
                    'reference'   => $payment['reference'] ?? null,
                    'note'        => $payment['note'] ?? null,
                    'allocations' => $allocations,
                ];

                $createdReceipts[] = app(\App\Services\CustomerPaymentService::class)->create($receiptPayload);
            }

            // Update UI labels/statuses
            $this->updateSaleStatus($sale);
            // $this->updatePaymentStatus($sale);

            return ApiResponse::success([
                'sale' => $sale->fresh(['items', 'payments']),
                'receipts' => $createdReceipts ?: null,
            ], 'Sale created and posted to ledger');
        });
    }



    // Helper to update status
    protected function updateSaleStatus(Sale $sale)
    {
        $paid = $sale->payments()->sum('amount');
        if ($paid >= $sale->total) {
            $sale->update(['status' => 'paid']);
        } elseif ($paid > 0) {
            $sale->update(['status' => 'partial']);
        } else {
            $sale->update(['status' => 'pending']);
        }
    }

    public function update(Request $request, $id)
    {
        // Only allow updating discount & tax from this endpoint (as per your UI)
        $data = $request->validate([
            'discount' => 'nullable|numeric|min:0',
            'tax'      => 'nullable|numeric|min:0',
        ]);

        $sale = Sale::with(['items', 'payments'])->findOrFail($id);

        // Block edits on finalised/cancelled sales
        if (in_array($sale->status, ['cancelled', 'void', 'returned'])) {
            return ApiResponse::error("This sale can't be edited in its current status.", 422);
        }

        return DB::transaction(function () use ($sale, $data) {
            // Snapshot old totals BEFORE change
            $old = [
                'subtotal' => (float)$sale->subtotal,
                'discount' => (float)$sale->discount,
                'tax'      => (float)$sale->tax,
                'total'    => (float)$sale->total,
            ];

            // Recompute subtotal from items (items not edited here)
            $subtotal = $sale->items->sum(fn($i) => ((float)$i->quantity) * ((float)$i->price));

            $discount = array_key_exists('discount', $data) ? (float)$data['discount'] : (float)$sale->discount;
            $tax      = array_key_exists('tax', $data)      ? (float)$data['tax']      : (float)$sale->tax;

            $total = max(0, round($subtotal - $discount + $tax, 2));

            // Persist new totals
            $sale->update([
                'subtotal' => round($subtotal, 2),
                'discount' => round($discount, 2),
                'tax'      => round($tax, 2),
                'total'    => $total,
            ]);

            // Recompute gross profit using existing cogs stored on sale (items unchanged)
            $cogs = (float)$sale->cogs; // assumes cogs was previously set by deductStockAndStampCosts
            $sale->update([
                'gross_profit' => round($total - $cogs, 2),
            ]);

            // Post delta JE to adjust ledger (mirrors PurchaseAdjustmentService behaviour)
            app(\App\Services\SaleAdjustmentService::class)->postSaleAdjustment(
                $sale->fresh(['items']),
                $old,
                [
                    'subtotal' => round($subtotal, 2),
                    'discount' => round($discount, 2),
                    'tax'      => round($tax, 2),
                    'total'    => $total,
                ],
                now()->toDateString()
            );

            // Optional: recompute payment/allocation statuses for UI
            // $this->updatePaymentStatus($sale);
            $this->updateSaleStatus($sale);

            return ApiResponse::success($sale->fresh(['items', 'payments']), 'Sale updated and ledger adjusted');
        });
    }


    protected function validateStock(int $branchId, array $items): array
    {
        $productIds = collect($items)->pluck('product_id');

        // Fetch stock quantities for this branch
        $stocks = DB::table('product_stocks')
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $productIds)
            ->pluck('quantity', 'product_id'); // product_id => quantity

        // Fetch product names once
        $products = Product::whereIn('id', $productIds)
            ->pluck('name', 'id'); // product_id => name

        foreach ($items as $item) {
            $productId = $item['product_id'];
            $requiredQty = $item['quantity'];
            $available = $stocks[$productId] ?? null;

            if ($available === null) {
                return [
                    'ok' => false,
                    'message' => "No stock record found for " . ($products[$productId] ?? "Product #$productId") . " at branch $branchId"
                ];
            }

            if ($available < $requiredQty) {
                return [
                    'ok' => false,
                    'message' => "Insufficient stock for " . ($products[$productId] ?? "Product #$productId") . " (Available: $available, Requested: $requiredQty)"
                ];
            }
        }

        return ['ok' => true, 'message' => null];
    }
}
