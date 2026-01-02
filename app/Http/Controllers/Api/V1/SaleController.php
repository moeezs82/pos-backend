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

        // if ($request->has('branch_id')) {
        //     $query->where('branch_id', $request->branch_id);
        // }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('sale_type')) {
            $query->where('sale_type', $request->sale_type);
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

    public function show(Request $request, $id)
    {
        $includeBalance = $request->boolean('include_balance'); // ?include_balance=1
        $branchId       = $request->integer('branch_id');       // optional branch scope

        $sale = Sale::with([
            'customer:id,first_name,last_name',
            'branch',
            'items.product:id,name',
            'payments',
            'vendor:id,first_name,last_name',
            'salesman:id,name',
            'deliveryBoy:id,name'
        ])->findOrFail($id);

        $asOf = $sale->created_at;             // optional ISO date/time, e.g. 2025-10-26 or 2025-10-26 23:59:59

        // If there is no customer or balance not requested, return as is
        if (!$includeBalance || !$sale->customer_id) {
            return ApiResponse::success($sale);
        }

        // ---- Compute AR snapshot for this customer (matches your index() approach) ----
        $customerId = (int)$sale->customer_id;
        $customerFqcn = \App\Models\Customer::class;
        $partyTypes   = ['customer', $customerFqcn];

        $jp = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->selectRaw("
            SUM(CASE WHEN jp.debit  > 0 THEN jp.debit  ELSE 0 END) AS tot_sales,
            SUM(CASE WHEN jp.credit > 0 THEN jp.credit ELSE 0 END) AS tot_receipts,
            SUM(jp.debit - jp.credit)                              AS balance,
            MAX(COALESCE(jp.created_at, je.created_at))            AS last_activity_at
        ")
            ->whereIn('jp.party_type', $partyTypes)
            ->where('jp.party_id', $customerId);

        // Optional branch scope (on journal_entries)
        if ($branchId > 0) {
            $jp->where('je.branch_id', $branchId);
        }

        // Optional "as of" cutoff (<= as_of)
        if (!empty($asOf)) {
            // Use je.created_at as the canonical posting timestamp (adjust if you use a different column)
            $jp->where('je.created_at', '<', $asOf);
        }

        // Optional: restrict to AR accounts only if you keep non-AR traffic in journal_postings
        // $jp->whereIn('jp.account_id', [1200,1201]);

        $row = $jp->first();

        $ar = [
            'total_sales'      => (float)($row->tot_sales ?? 0),
            'total_receipts'   => (float)($row->tot_receipts ?? 0),
            'balance'          => (float)($row->balance ?? 0),
            'last_activity_at' => isset($row->last_activity_at) ? (string)$row->last_activity_at : null,
            'branch_id'        => $branchId > 0 ? (int)$branchId : null,
            'as_of'            => $asOf ?: null,
        ];

        // Attach under customer to keep the payload tidy (or put as $sale->customer_balance if you prefer)
        if ($sale->relationLoaded('customer') && $sale->customer) {
            $sale->customer->setAttribute('ar_summary', $ar);
        } else {
            // Fallback if customer relation not loaded for some reason
            $sale->setAttribute('customer_ar_summary', $ar);
        }

        return ApiResponse::success($sale);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'vendor_id'   => 'nullable|exists:vendors,id',
            'salesman_id' => 'nullable|exists:users,id',
            'delivery_boy_id' => 'nullable|exists:users,id',
            'created_by'  => 'nullable|exists:users,id',
            // 'branch_id'   => 'nullable|exists:branches,id',
            'items'       => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.discount_pct' => 'nullable|numeric',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.price'      => 'required|numeric|min:0',
            'discount'    => 'nullable|numeric|min:0',
            'tax'         => 'nullable|numeric|min:0',
            'delivery'         => 'nullable|numeric|min:0',
            'payments'    => 'array',
            'meta' => 'nullable|array',
            'sale_type' => 'nullable|string|in:dine_in,takeaway,delivery,self',
        ]);

        $branchId = $data['branch_id'] ?? null;

        return DB::transaction(function () use ($data, $branchId) {
            // totals
            $subtotal = collect($data['items'])->sum(function ($i) {
                $qty   = (float)($i['quantity']      ?? 0);
                $price = (float)($i['price']         ?? 0);
                $pct   = (float)($i['discount_pct']  ?? 0);   // 0–100

                $pct   = max(0, min(100, $pct));              // clamp
                $line  = $qty * $price;
                $line -= $line * ($pct / 100);                // apply % off

                return max(0, $line);                         // no negatives
            });
            $discount = (float)($data['discount'] ?? 0);
            $tax      = (float)($data['tax'] ?? 0);
            $delivery      = (float)($data['delivery'] ?? 0);
            $total    = max(0, round($subtotal - $discount + $tax + $delivery, 2));

            // create sale header
            $sale = Sale::create([
                'invoice_no'  => 'INV-' . time(),
                'customer_id' => $data['customer_id'] ?? null,
                'vendor_id'   => $data['vendor_id'] ?? null,
                'salesman_id' => $data['salesman_id'] ?? null,
                'delivery_boy_id' => $data['delivery_boy_id'] ?? null,
                'created_by'  => $data['created_by'] ?? auth()->id(),
                'branch_id'   => $branchId,
                'subtotal'    => round($subtotal, 2),
                'discount'    => round($discount, 2),
                'tax'         => round($tax, 2),
                'delivery'    => round($delivery, 2),
                'total'       => $total,
                'status'      => 'pending',
                'meta'        => $data['meta'] ?? [],
                'sale_type' => $data['sale_type'] ?? 'dine_in',
            ]);

            // gather product IDs once
            $productIds = collect($data['items'])
                ->pluck('product_id')
                ->map(fn($id) => (int)$id)
                ->unique()
                ->values();

            // Build a {product_id => avg_cost} map without N+1.
            // If product_stocks has multiple rows per product, we take the latest (by id) per product for this branch.
            $costByProduct = DB::table('product_stocks as ps')
                ->join(
                    DB::raw('(SELECT product_id, MAX(id) AS max_id FROM product_stocks WHERE branch_id = ' . (int)$sale->branch_id . ' GROUP BY product_id) latest'),
                    'latest.max_id',
                    '=',
                    'ps.id'
                )
                ->where('ps.branch_id', $sale->branch_id)
                ->whereIn('ps.product_id', $productIds)
                ->pluck('ps.avg_cost', 'ps.product_id'); // -> { product_id: avg_cost }
            $totalCogs = 0;

            // create items (do not duplicate stock decrement here — handled by deductStockAndStampCosts)
            foreach ($data['items'] as $item) {
                $productId = (int)$item['product_id'];
                $qty       = (int)$item['quantity'];
                $price     = (float)$item['price'];

                // New: discount % (clamped between 0 and 100)
                $discountPct = isset($item['discount_pct']) ? (float)$item['discount_pct'] : 0.0;
                $discountPct = max(0.0, min(100.0, $discountPct));

                // Line math (round at money boundaries)
                $lineSubtotal = round($qty * $price, 2);
                $lineDiscount = round($lineSubtotal * ($discountPct / 100.0), 2);
                $lineTotal    = round($lineSubtotal - $lineDiscount, 2);

                // COGS stays the same (discount affects revenue, not cost)
                $unitCost = (float)($costByProduct[$productId] ?? 0.0);
                $lineCost = round($unitCost * $qty, 2);
                $totalCogs += $lineCost;

                $sale->items()->create([
                    'product_id' => $productId,
                    'quantity'   => $qty,
                    'price'      => $price,            // unit price before discount
                    'discount'   => $discountPct,      // store the % value you added
                    'total'      => $lineTotal,        // NET line total after % discount
                    // costs
                    'unit_cost'  => $unitCost,
                    'line_cost'  => $lineCost,
                ]);
            }
            $sale->cogs = round($totalCogs, 2);

            // Ensure $sale->total (revenue) is computed from discounted line totals elsewhere.
            // If you need to do it here, uncomment the next line:
            // $sale->total = $sale->items()->sum('total');

            $sale->gross_profit = round(($sale->total ?? 0) - $sale->cogs, 2);
            $sale->save();

            // Deduct stock, stamp costs (sets unit_cost & line_cost on items) and update sale.cogs/gross_profit
            // This method uses InventoryValuationService->avgCost(...) and writes unit_cost/line_cost, product_stocks, stock movements
            app(\App\Services\SalePostingService::class)->deductStockAndStampCosts($sale->fresh('items'));

            // Post Sales JE (AR / Revenue / Tax / COGS / Inventory)
            app(\App\Services\SalePostingService::class)->postSale($sale->fresh('items'));

            // Optional immediate receipts/payments
            $createdReceipts = [];
            foreach (($data['payments'] ?? []) as $payment) {
                // auto-allocate to this sale if allocations missing
                // $allocations = $payment['allocations'] ?? null;
                // if (empty($allocations)) {
                //     $allocations = [
                //         ['sale_id' => $sale->id, 'amount' => min((float)$payment['amount'], (float)$sale->total)]
                //     ];
                // }

                $receiptPayload = [
                    'customer_id' => $sale->customer_id,
                    'branch_id'   => $sale->branch_id,
                    'sale_id' => $sale->id,
                    'received_at' => $payment['paid_at'] ?? now()->toDateString(),
                    'method'      => $payment['method'] ?? 'cash',
                    'amount'      => (float)$payment['amount'],
                    'reference'   => $payment['reference'] ?? "Payment for Sale #{$sale->invoice_no}",
                    'memo'   => "Payment for Sale #{$sale->invoice_no}",
                    'note'        => $payment['note'] ?? null,
                    // 'allocations' => $allocations,
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

    public function updateDeliveryBoy(Request $request, $id)
    {
        $data = $request->validate([
            'delivery_boy_id' => 'nullable|exists:users,id'
        ]);

        Sale::findOrFail($id);
        $sale = Sale::findOrFail($id);
        $sale->update($data);
        return ApiResponse::success($sale->fresh(), 'Delivery boy updated successfully');
    }

    public function update(Request $request, $id)
    {
        // Only allow updating discount & tax from this endpoint (as per your UI)
        $data = $request->validate([
            'discount' => 'nullable|numeric|min:0',
            'tax'      => 'nullable|numeric|min:0',
            'delivery'      => 'nullable|numeric|min:0',
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
                'delivery'      => (float)$sale->delivery,
                'total'    => (float)$sale->total,
            ];

            // Recompute subtotal from items (items not edited here)
            $subtotal = $sale->items->sum(function ($i) {
                $qty       = (float) $i->quantity;
                $price     = (float) $i->price;
                $discPct   = (float) ($i->discount ?? 0); // e.g. 10 for 10%

                $lineTotal = $qty * $price;
                $discValue = $lineTotal * ($discPct / 100);

                return $lineTotal - $discValue;
            });

            $discount = array_key_exists('discount', $data) ? (float)$data['discount'] : (float)$sale->discount;
            $tax      = array_key_exists('tax', $data)      ? (float)$data['tax']      : (float)$sale->tax;
            $delivery = array_key_exists('delivery', $data) ? (float)$data['delivery'] : (float)$sale->delivery;

            $total = max(0, round($subtotal - $discount + $tax + $delivery, 2));

            // Persist new totals
            $sale->update([
                'subtotal' => round($subtotal, 2),
                'discount' => round($discount, 2),
                'tax'      => round($tax, 2),
                'delivery' => round($delivery, 2),
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
                    'delivery' => round($delivery, 2),
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
