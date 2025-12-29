<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Vendor;
use App\Services\VendorPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    /* ===================== Listing / Show ===================== */

    public function index(Request $request)
    {
        $query = Purchase::with(['vendor', 'branch'])
            ->withSum('payments as paid_amount', 'amount');

        // if ($request->filled('branch_id')) {
        //     $query->where('branch_id', $request->branch_id);
        // }
        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_no', 'like', "%$search%")
                    ->orWhereHas('vendor', function ($v) use ($search) {
                        $v->where('first_name', 'like', "%$search%")
                            ->where('last_name', 'like', "%$search%")
                            ->orWhere('email', 'like', "%$search%")
                            ->orWhere('phone', 'like', "%$search%");
                    });
            });
        }

        $request->sort_by === 'total'
            ? $query->orderBy('total', 'desc')
            : $query->orderBy('created_at', 'desc');

        $purchases = $query->paginate($request->get('per_page', 15));

        return ApiResponse::success($purchases);
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'discount' => 'nullable|numeric|min:0',
            'tax'      => 'nullable|numeric|min:0',
        ]);

        $purchase = Purchase::with(['items'])->findOrFail($id);

        return DB::transaction(function () use ($purchase, $data) {
            // Snapshot old totals BEFORE change
            $old = [
                'subtotal' => (float)$purchase->subtotal,
                'discount' => (float)$purchase->discount,
                'tax'      => (float)$purchase->tax,
                'total'    => (float)$purchase->total,
            ];

            // Recompute subtotal from current items (no edits to items in this endpoint)
            $subtotal = $purchase->items->sum(function ($i) {
                $qty       = (float) $i->quantity;
                $price     = (float) $i->price;
                $discPct   = (float) ($i->discount ?? 0); // e.g. 10 for 10%

                $lineTotal = $qty * $price;
                $discValue = $lineTotal * ($discPct / 100);

                return $lineTotal - $discValue;
            });

            $discount = array_key_exists('discount', $data) ? (float)$data['discount'] : (float)$purchase->discount;
            $tax      = array_key_exists('tax', $data) ? (float)$data['tax']      : (float)$purchase->tax;

            $total = max(0, $subtotal - $discount + $tax);

            // Persist new totals
            $purchase->update([
                'subtotal' => round($subtotal, 2),
                'discount' => round($discount, 2),
                'tax'      => round($tax, 2),
                'total'    => round($total, 2),
            ]);

            // Post delta JE
            app(\App\Services\PurchaseAdjustmentService::class)->postBillAdjustment(
                p: $purchase->fresh(['items']),
                old: $old,
                new: [
                    'subtotal' => round($subtotal, 2),
                    'discount' => round($discount, 2),
                    'tax'      => round($tax, 2),
                    'total'    => round($total, 2),
                ],
                date: now()->toDateString()
            );

            // Optional: recompute payment status, if you keep that UI label
            $this->updatePaymentStatus($purchase);

            return ApiResponse::success($purchase->fresh(['items']), 'Purchase updated');
        });
    }

    public function show(Purchase $purchase)
    {
        $purchase->load(['vendor', 'branch', 'items.product', 'payments']);
        return ApiResponse::success($purchase);
    }

    /* ===================== Create PO ===================== */
    public function store(Request $request, VendorPaymentService $vendorPaymentService)
    {
        $data = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            // 'branch_id' => 'nullable|exists:branches,id',
            'invoice_date' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.price'      => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric',
            'discount' => 'nullable|numeric|min:0',
            'tax'      => 'nullable|numeric|min:0',
            'expected_at' => 'nullable|date',
            'notes'    => 'nullable|string',
            'receive_now' => 'boolean',
            'items.*.received_qty' => 'nullable|integer|min:0',

            // optional payment block
            'payment' => 'nullable|array',
            'payment.method' => 'required_with:payment|string|in:cash,bank,card,wallet',
            'payment.amount' => 'required_with:payment|numeric|min:0.01',
            'payment.paid_at' => 'nullable|date',
            'payment.reference' => 'nullable|string',
            'payment.note' => 'nullable|string'
        ]);

        $receiveNow = (bool)($data['receive_now'] ?? false);

        return DB::transaction(function () use ($data, $receiveNow, $vendorPaymentService) {
            // totals
            $subtotal = collect($data['items'])->sum(function ($i) {
                $qty   = (float)($i['quantity']      ?? 0);
                $price = (float)($i['price']         ?? 0);
                $pct   = (float)($i['discount']  ?? 0);   // 0–100

                $pct   = max(0, min(100, $pct));              // clamp
                $line  = $qty * $price;
                $line -= $line * ($pct / 100);                // apply % off

                return max(0, $line);                         // no negatives
            });
            $total    = max(0, $subtotal - ($data['discount'] ?? 0) + ($data['tax'] ?? 0));

            $p = Purchase::create([
                'invoice_no'     => $this->generateNumber('PUR'),
                'vendor_id'      => $data['vendor_id'],
                // 'branch_id'      => $data['branch_id'],
                'invoice_date'   => $data['invoice_date'] ?? now()->toDateString(),
                'subtotal'       => round($subtotal, 2),
                'discount'       => round($data['discount'] ?? 0, 2),
                'tax'            => round($data['tax'] ?? 0, 2),
                'total'          => round($total, 2),
                'status'         => 'pending',
                'receive_status' => $receiveNow ? 'partial' : 'ordered',
                'expected_at'    => $data['expected_at'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'created_by'     => auth()->id(),
            ]);

            // create items and optionally receive immediately
            $receiveRows = [];
            foreach ($data['items'] as $row) {
                // New: discount % (clamped between 0 and 100)
                $discountPct = isset($row['discount']) ? (float)$row['discount'] : 0.0;
                $discountPct = max(0.0, min(100.0, $discountPct));

                // Line math (round at money boundaries)
                $lineSubtotal = round((int)$row['quantity'] * (float)$row['price'], 2);
                $lineDiscount = round($lineSubtotal * ($discountPct / 100.0), 2);
                $lineTotal    = round($lineSubtotal - $lineDiscount, 2);

                $item = $p->items()->create([
                    'product_id'   => $row['product_id'],
                    'quantity'     => (int)$row['quantity'],
                    'received_qty' => 0,
                    'price'        => (float)$row['price'],
                    'discount'     => (float)$row['discount'],
                    'total'        => $lineTotal,
                ]);

                $toReceive = (int) $row['quantity'];
                if ($toReceive > 0) {
                    $receiveRows[] = [
                        'item_id'     => $item->id,
                        'receive_qty' => $toReceive,
                    ];
                }

                // receive immediately (updates stock + avg_cost + movement only)
                app(\App\Services\InventoryValuationWriteService::class)->receivePurchase(
                    productId: $item->product_id,
                    branchId: $p->branch_id,
                    receiveQty: $item->quantity,
                    unitPrice: $item->price,
                    ref: $p->invoice_no
                );
            }

            // Post the **vendor bill** to GL (AP)
            app(\App\Services\PurchasePostingService::class)->postVendorBill($p, $p->invoice_date);

            // OPTIONAL: process immediate vendor payment (if provided)
            $payment = $data['payment'] ?? null;
            $vp = null;
            if ($payment) {
                // If allocations not provided, auto-allocate the payment to this purchase
                // $allocations = $payment['allocations'] ?? null;
                // if (empty($allocations)) {
                //     $allocations = [
                //         ['purchase_id' => $p->id, 'amount' => min((float)$payment['amount'], (float)$p->total)]
                //     ];
                // }

                $vpData = [
                    'vendor_id'   => $p->vendor_id,
                    'branch_id'   => $p->branch_id,
                    'purchase_id' => $p->id,
                    'paid_at'     => $payment['paid_at'] ?? now()->toDateString(),
                    'method'      => $payment['method'],
                    'amount'      => $payment['amount'],
                    'memo'   => $payment['reference'] ?? "Purchase time payment for $p->invoice_no",
                    'reference'   => $payment['reference'] ?? "Purchase time payment for $p->invoice_no",
                    'note'        => $payment['note'] ?? null,
                    // 'allocations' => $allocations,
                ];

                // Use the reusable service
                $vp = $vendorPaymentService->create($vpData);
            }

            // return both purchase and optional payment for UI
            return ApiResponse::success([
                'purchase' => $p->load('items'),
            ], 'Purchase created');
        });
    }

    /* ===================== Receive (Partial Allowed) ===================== */

    public function receive(Request $request, Purchase $purchase)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.receive_qty' => 'required|integer|min:1',
            'reference' => 'nullable|string', // GRN
            'received_at' => 'nullable|date',
        ]);

        if ($purchase->receive_status === 'cancelled') {
            return ApiResponse::error('Purchase is cancelled, receiving not allowed.', 422);
        }

        return DB::transaction(function () use ($purchase, $data) {
            $branchId  = $purchase->branch_id;
            $grnNumber = $data['reference'] ?? $this->generateNumber('GRN');

            $itemsMap = $purchase->items()->get()->keyBy('product_id');

            foreach ($data['items'] as $in) {
                $productId = (int) $in['product_id'];
                $receive   = (int) $in['receive_qty'];

                $pi = $itemsMap->get($productId);
                if (!$pi) {
                    return ApiResponse::error("Product $productId is not part of this purchase.", 422);
                }

                $remaining = $pi->quantity - $pi->received_qty;
                if ($remaining <= 0) {
                    return ApiResponse::error("Product {$productId} already fully received.", 422);
                }
                if ($receive > $remaining) {
                    return ApiResponse::error("Receive qty ($receive) exceeds remaining ($remaining) for product {$productId}.", 422);
                }

                $this->incrementStock($productId, $branchId, $receive);
                $pi->increment('received_qty', $receive);

                StockMovement::create([
                    'product_id' => $productId,
                    'branch_id'  => $branchId,
                    'type'       => 'purchase',
                    'quantity'   => $receive,
                    'reference'  => $grnNumber,
                ]);
            }

            $this->updateReceiveStatus($purchase->fresh('items'));

            return ApiResponse::success(['purchase' => $purchase]);
        });
    }

    /* ===================== Payments: Add / Update / Delete ===================== */

    public function addPayment(Request $request, Purchase $purchase, VendorPaymentService $vendorPaymentService)
    {
        $data = $request->validate([
            'method' => 'nullable|string',
            'amount' => 'required|numeric|min:0.01',
            // 'tx_ref' => 'nullable|string',
            // 'paid_at' => 'nullable|date',
            // 'meta'   => 'nullable|array',
        ]);
        $data['vendor_id'] = $purchase->vendor_id;
        $data['branch_id'] = $purchase->branch_id;
        $data['purchase_id'] = $purchase->id;
        $data['reference'] = "Payment for purchase $purchase->invoice_no";
        $data['memo'] = "Payment for purchase $purchase->invoice_no";
        // $data['allocations'][] = [
        //     'purchase_id' => $purchase->id,
        //     'amount' => $data['amount']
        // ];

        return DB::transaction(function () use ($purchase, $data, $vendorPaymentService) {
            $vp = $vendorPaymentService->create($data);
            $this->recalculatePurchase($purchase->fresh());

            return ApiResponse::success(['purchase' => $purchase], 'Payment added');
        });
    }

    public function updatePayment(Request $request, Purchase $purchase, $paymentId)
    {
        $data = $request->validate([
            'method' => 'sometimes|string|nullable',
            'amount' => 'sometimes|numeric|min:0.01',
            'tx_ref' => 'nullable|string',
            'paid_at' => 'nullable|date',
            'meta'   => 'nullable|array',
        ]);

        return DB::transaction(function () use ($purchase, $paymentId, $data) {
            $payment = $purchase->payments()->findOrFail($paymentId);
            $payment->update($data);

            $this->recalculatePurchase($purchase->fresh());

            return ApiResponse::success(['payment' => $payment], 'Payment updated');
        });
    }

    public function deletePayment(Purchase $purchase, $paymentId)
    {
        return DB::transaction(function () use ($purchase, $paymentId) {
            $payment = $purchase->payments()->findOrFail($paymentId);
            dd($payment->payment, $payment);
            $payment->delete();

            $this->recalculatePurchase($purchase->fresh());

            return ApiResponse::success(null, 'Payment deleted');
        });
    }

    /* ===================== Items: Add / Update / Delete ===================== */

    public function addItem(Request $request, Purchase $purchase)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1',
            'price'      => 'required|numeric|min:0',
            'discount'      => 'nullable|numeric|min:0',
        ]);

        $branchId = $purchase->branch_id;

        return DB::transaction(function () use ($purchase, $data, $branchId) {
            // Snapshot old totals
            $old = [
                'subtotal' => (float)$purchase->subtotal,
                'discount' => (float)$purchase->discount,
                'tax'      => (float)$purchase->tax,
                'total'    => (float)$purchase->total,
            ];

            // New: discount % (clamped between 0 and 100)
            $discountPct = isset($data['discount']) ? (float)$data['discount'] : 0.0;
            $discountPct = max(0.0, min(100.0, $discountPct));

            // Line math (round at money boundaries)
            $lineSubtotal = round((int)$data['quantity'] * (float)$data['price'], 2);
            $lineDiscount = round($lineSubtotal * ($discountPct / 100.0), 2);
            $lineTotal    = round($lineSubtotal - $lineDiscount, 2);

            // Create line (ordered == received now)
            $item = $purchase->items()->create([
                'product_id' => (int)$data['product_id'],
                'quantity'   => (int)$data['quantity'],
                'price'      => (float)$data['price'],
                'discount'   => (float)$data['discount'] ?? 0.00,
                'total'      => $lineTotal,
            ]);

            // Receive into stock at line price (affects avg_cost)
            app(\App\Services\InventoryValuationWriteService::class)->receivePurchase(
                productId: $item->product_id,
                branchId: $branchId,
                receiveQty: $item->quantity,
                unitPrice: $item->price,
                ref: $purchase->invoice_no
            );

            // Recompute purchase totals
            $subtotal = $purchase->items()->sum('total');
            $total    = max(0, $subtotal - $purchase->discount + $purchase->tax);
            $purchase->update([
                'subtotal' => round($subtotal, 2),
                'total'    => round($total, 2),
            ]);

            // Post delta JE (goods to Inventory, AP opposite; tax handled by header edits if any)
            app(\App\Services\AccountingService::class)->post(
                branchId: $purchase->branch_id,
                memo: "Purchase #{$purchase->invoice_no} - add item productId: $item->product_id",
                reference: $purchase,
                lines: [
                    ['account_code' => '1400', 'debit' => $item->total, 'credit' => 0],              // Inventory
                    ['account_code' => '2000', 'debit' => 0, 'credit' => $item->total, 'party_type' => Vendor::class, 'party_id' => $purchase->vendor_id],              // AP
                ],
                entryDate: now()->toDateString(),
                userId: auth()->id()
            );

            return ApiResponse::success(['item' => $item], 'Item added');
        });
    }

    public function updateItem(Request $request, Purchase $purchase, $itemId)
    {
        $data = $request->validate([
            'quantity' => 'sometimes|integer|min:1',
            'price'    => 'sometimes|numeric|min:0',
        ]);

        $branchId = (int) $purchase->branch_id;

        return DB::transaction(function () use ($purchase, $itemId, $data, $branchId) {
            $item = $purchase->items()->lockForUpdate()->findOrFail($itemId);

            // Snapshot BEFORE
            $oldQty   = (int)   $item->quantity;
            $oldPrice = (float) $item->price;
            $oldSub   = (float) $purchase->subtotal;
            $oldTot   = (float) $purchase->total;

            // New values (default to old)
            $newQty   = array_key_exists('quantity', $data) ? (int)$data['quantity'] : $oldQty;
            $newPrice = array_key_exists('price',   $data) ? (float)$data['price']   : $oldPrice;

            $qtyDelta = $newQty - $oldQty;

            // --- 1) Handle quantity changes (these are the ONLY times avg_cost may change) ---
            if ($qtyDelta > 0) {
                // Receive ONLY the delta qty at new price (updates stock qty + avg_cost)
                app(\App\Services\InventoryValuationWriteService::class)->receivePurchase(
                    productId: $item->product_id,
                    branchId: $branchId,
                    receiveQty: $qtyDelta,
                    unitPrice: $newPrice,
                    ref: $purchase->invoice_no
                );

                // JE: DR Inventory, CR AP for the delta line value
                $lineValue = round($qtyDelta * $newPrice, 2);
                app(\App\Services\AccountingService::class)->post(
                    branchId: $purchase->branch_id,
                    memo: "Purchase #{$purchase->invoice_no} - qty increase",
                    reference: $purchase,
                    lines: [
                        ['account_code' => '1400', 'debit' => $lineValue, 'credit' => 0],
                        ['account_code' => '2000', 'debit' => 0, 'credit' => $lineValue, 'party_type' => Vendor::class, 'party_id' => $purchase->vendor_id],
                    ],
                    entryDate: now()->toDateString(),
                    userId: auth()->id()
                );
            } elseif ($qtyDelta < 0) {
                // Return ONLY the delta qty at CURRENT avg (no revaluation)
                $avg = app(\App\Services\InventoryValuationWriteService::class)->returnToVendor(
                    productId: $item->product_id,
                    branchId: $branchId,
                    returnQty: -$qtyDelta,
                    ref: $purchase->invoice_no
                );

                // AP down at line price; Inventory down at avg; difference -> PPV
                $lineValue   = abs($qtyDelta) * $oldPrice;
                $inventoryCr = abs($qtyDelta) * $avg;
                $ppvDelta    = round($lineValue - $inventoryCr, 2);

                $lines = [
                    ['account_code' => '2000', 'debit' => $lineValue, 'credit' => 0, 'party_type' => Vendor::class, 'party_id' => $purchase->vendor_id],
                    ['account_code' => '1400', 'debit' => 0, 'credit' => $inventoryCr],
                ];
                if ($ppvDelta != 0.0) {
                    $lines[] = [
                        'account_code' => '5205',
                        'debit'  => $ppvDelta > 0 ?  $ppvDelta : 0,
                        'credit' => $ppvDelta < 0 ? -$ppvDelta : 0,
                    ];
                }

                app(\App\Services\AccountingService::class)->post(
                    branchId: $purchase->branch_id,
                    memo: "Purchase #{$purchase->invoice_no} - qty decrease",
                    reference: $purchase,
                    lines: $lines,
                    entryDate: now()->toDateString(),
                    userId: auth()->id()
                );
            }

            // --- 2) Update the line itself (no stock touch here) ---
            $item->update([
                'quantity' => $newQty,
                'price'    => $newPrice,
                'total'    => $newQty * $newPrice,
            ]);

            // --- 3) Price-only delta on EXISTING qty (no avg change, no stock move) ---
            if ($qtyDelta === 0 && $newPrice !== $oldPrice) {
                // Move the difference to PPV vs AP (Inventory untouched)
                $amt = round(($newPrice - $oldPrice) * $oldQty, 2);
                if ($amt != 0.0) {
                    app(\App\Services\AccountingService::class)->post(
                        branchId: $purchase->branch_id,
                        memo: "Purchase #{$purchase->invoice_no} - price adjustment",
                        reference: $purchase,
                        lines: [
                            ['account_code' => '5205', 'debit' => $amt > 0 ? $amt : 0, 'credit' => $amt < 0 ? abs($amt) : 0],
                            ['account_code' => '2000', 'debit' => $amt < 0 ? abs($amt) : 0, 'credit' => $amt > 0 ? $amt : 0, 'party_type' => Vendor::class, 'party_id' => $purchase->vendor_id],
                        ],
                        entryDate: now()->toDateString(),
                        userId: auth()->id()
                    );
                }
            }

            // --- 4) Recompute header totals (for UI/reporting only) ---
            $subtotal = $purchase->items()->sum(DB::raw('quantity * price'));
            $total    = max(0, $subtotal - $purchase->discount + $purchase->tax);
            $purchase->update([
                'subtotal' => round($subtotal, 2),
                'total'    => round($total, 2),
            ]);

            return ApiResponse::success(['item' => $item->fresh()], 'Item updated');
        });
    }

    public function deleteItem(Purchase $purchase, $itemId)
    {
        $branchId = (int) $purchase->branch_id;

        return DB::transaction(function () use ($purchase, $itemId, $branchId) {
            $item = $purchase->items()->lockForUpdate()->findOrFail($itemId);

            $qty = (int) $item->quantity;
            if ($qty > 0) {
                // Return all qty to vendor at avg (no revaluation)
                $avg = app(\App\Services\InventoryValuationWriteService::class)->returnToVendor(
                    productId: $item->product_id,
                    branchId: $branchId,
                    returnQty: $qty,
                    ref: $purchase->invoice_no
                );

                // --- Currency rounding to 2dp everywhere ---
                $lineValue   = round($qty * (float) $item->price, 2); // AP reduction at line price
                $inventoryCr = round($qty * (float) $avg, 2);         // Inventory credit at avg (rounded)

                // PPV to bridge line vs avg after rounding
                $ppvDelta = round($lineValue - $inventoryCr, 2);      // DR if positive, CR if negative

                // Build lines
                $lines = [
                    ['account_code' => '2000', 'debit' => $lineValue,            'credit' => 0.00, 'party_type' => Vendor::class, 'party_id' => $purchase->vendor_id], // reduce AP
                    ['account_code' => '1400', 'debit' => 0.00,                  'credit' => $inventoryCr], // reduce Inventory
                ];
                if ($ppvDelta != 0.00) {
                    $lines[] = [
                        'account_code' => '5205',
                        'debit'  => $ppvDelta > 0 ?  $ppvDelta : 0.00,
                        'credit' => $ppvDelta < 0 ? -$ppvDelta : 0.00,
                    ];
                }

                // Final tiny balancing (handles rare 0.01 drift after all rounding)
                $sumD = 0.00;
                $sumC = 0.00;
                foreach ($lines as $l) {
                    $sumD += $l['debit'];
                    $sumC += $l['credit'];
                }
                $diff = round($sumD - $sumC, 2); // +ve means too much debit
                if ($diff !== 0.00) {
                    $lines[] = [
                        'account_code' => '5205',
                        'debit'  => $diff < 0 ? round(abs($diff), 2) : 0.00, // add debit if credits > debits
                        'credit' => $diff > 0 ? round(abs($diff), 2) : 0.00, // add credit if debits > credits
                    ];
                }

                app(\App\Services\AccountingService::class)->post(
                    branchId: $purchase->branch_id,
                    memo: "Purchase #{$purchase->invoice_no} - delete item (return)",
                    reference: $purchase,
                    lines: $lines,
                    entryDate: now()->toDateString(),
                    userId: auth()->id()
                );
            }

            $item->delete();

            // Recompute header totals
            $subtotal = $purchase->items()->sum(DB::raw('quantity * price'));
            $total    = max(0, $subtotal - $purchase->discount + $purchase->tax);
            $purchase->update([
                'subtotal' => round($subtotal, 2),
                'total'    => round($total, 2),
            ]);

            return ApiResponse::success($purchase->load('items.product'), 'Item deleted');
        });
    }


    /* ===================== Cancel ===================== */

    public function cancel(Purchase $purchase)
    {
        $receivedAny = $purchase->items()->sum('received_qty') > 0;
        if ($receivedAny) {
            return ApiResponse::error('Cannot cancel: items already received.', 422);
        }

        $purchase->update(['receive_status' => 'cancelled']);
        return ApiResponse::success($purchase);
    }

    /* ===================== Helpers ===================== */

    protected function generateNumber(string $prefix): string
    {
        return $prefix . '-' . time() . rand(1000, 9999);
    }

    protected function recalculatePurchase(Purchase $purchase): void
    {
        // Subtotal/Total
        $subtotal = (float) $purchase->items()->sum(DB::raw('quantity * price'));
        $discount = (float) ($purchase->discount ?? 0);
        $tax      = (float) ($purchase->tax ?? 0);
        $total    = $subtotal - $discount + $tax;

        $purchase->update([
            'subtotal' => $subtotal,
            'total'    => $total,
        ]);

        // Payment status
        $this->updatePaymentStatus($purchase);

        // Receive status
        $this->updateReceiveStatus($purchase->fresh('items'));
    }

    protected function updatePaymentStatus(Purchase $purchase): void
    {
        $paid = (float) $purchase->payments()->sum('amount');
        if ($paid >= (float) $purchase->total && $purchase->total > 0) {
            $purchase->update(['status' => 'paid']);
        } elseif ($paid > 0) {
            $purchase->update(['status' => 'partial']);
        } else {
            $purchase->update(['status' => 'pending']);
        }
    }

    protected function updateReceiveStatus(Purchase $purchase): void
    {
        $items = $purchase->items;
        $totalOrdered  = (int) $items->sum('quantity');
        $totalReceived = (int) $items->sum('received_qty');

        $status = 'ordered';
        if ($totalReceived === 0) {
            $status = 'ordered';
        } elseif ($totalReceived < $totalOrdered) {
            $status = 'partial';
        } else {
            $status = 'received';
        }

        if ($purchase->receive_status !== 'cancelled') {
            $purchase->update(['receive_status' => $status]);
        }
    }

    /**
     * Increment product stock for a branch. Creates row if missing.
     * Pass negative $qty to decrement (adjustment).
     */
    protected function incrementStock(int $productId, int $branchId, int $qty): void
    {
        $affected = DB::table('product_stocks')
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->increment('quantity', $qty);

        if ($affected === 0) {
            DB::table('product_stocks')->insert([
                'product_id' => $productId,
                'branch_id'  => $branchId,
                'quantity'   => $qty,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
