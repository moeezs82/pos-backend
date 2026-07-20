<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\StockMovement;
use App\Services\BranchContextService;
use App\Services\BranchRoleService;
use App\Services\InvoiceSequenceService;
use App\Services\ProductBranchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\RegisterShift;

class SaleController extends Controller
{
    // List all sales
    public function index(Request $request, BranchContextService $branches)
    {
        $query = Sale::with(['customer', 'branch'])
            ->withSum('payments as paid_amount', 'amount');

        $branches->applyToQuery($query, $request, 'branch_id');
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
                    ->orWhere('offline_invoice_no', 'like', "%$search%")
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

    public function show(Request $request, BranchContextService $branches, $id)
    {
        $includeBalance = $request->boolean('include_balance'); // ?include_balance=1
        $branchId       = $branches->effectiveBranchId($request);       // optional branch scope

        $sale = Sale::with([
            'customer:id,first_name,last_name',
            'branch',
            'items.product:id,name',
            'payments',
            'vendor:id,first_name,last_name',
            'salesman:id,name',
            'deliveryBoy:id,name'
        ])->findOrFail($id);

        $branches->assertCanAccessBranch($request, $sale->branch_id ? (int) $sale->branch_id : null);

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

    public function store(Request $request, BranchContextService $branches, ProductBranchService $productBranches)
    {
        $data = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'vendor_id'   => 'nullable|exists:vendors,id',
            'salesman_id' => 'nullable|exists:users,id',
            'delivery_boy_id' => 'nullable|exists:users,id',
            'branch_id'   => 'nullable|exists:branches,id',
            'items'       => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.discount_pct' => 'nullable|numeric',
            'items.*.quantity'   => 'required|numeric|not_in:0',
            'items.*.price'      => 'required|numeric|min:0',
            'discount'    => 'nullable|numeric|min:0',
            'tax'         => 'nullable|numeric',
            'delivery'         => 'nullable|numeric|min:0',
            'payments'    => 'array',
            'meta' => 'nullable|array',
            'sale_type' => 'nullable|string|in:dine_in,takeaway,delivery,self',
            // Offline-sync idempotency (handover doc §1.1-1.3). Both optional
            // and additive — online sales that omit them behave exactly as
            // before.
            'client_ref'         => 'nullable|uuid',
            'occurred_at'        => 'nullable|date',
            'register_shift_client_ref' => 'nullable|uuid',
            // Customer-friendly offline receipt reference generated on-device.
            // Never trusted as an official invoice number — that is always
            // allocated by InvoiceSequenceService on the backend.
            'offline_invoice_no' => 'nullable|string|max:80',
        ]);

        // Idempotent replay: if this exact client_ref was already synced
        // (earlier attempt, double-tap of "Sync Now", a retried request
        // whose original response never made it back to the device), return
        // the existing sale instead of creating a duplicate. This check is
        // a fast-path; the DB `unique` constraint on client_ref is the real
        // guarantee and is handled via the catch below for the race where
        // two requests for the same client_ref land at (almost) the same
        // time.
        if (!empty($data['client_ref'])) {
            $existing = Sale::where('client_ref', $data['client_ref'])->first();
            if ($existing) {
                return ApiResponse::success([
                    'sale'                => $existing->fresh(['items', 'payments']),
                    'receipts'            => null,
                    'already_existed'     => true,
                    'invoice_no'          => $existing->invoice_no,
                    'offline_invoice_no'  => $existing->offline_invoice_no,
                ], 'Sale already recorded (idempotent replay)');
            }
        }

        $branchId = $branches->requireBranchId($request);

        $shiftQuery = RegisterShift::query()->where('branch_id', $branchId);
        if (!empty($data['register_shift_client_ref'])) {
            $shiftQuery->where('client_ref', $data['register_shift_client_ref']);
        } else {
            $shiftQuery->where('cashier_id', $request->user()->id)->where('status', 'open');
        }
        $registerShift = $shiftQuery->first();
        if (!$registerShift) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'register_shift' => ['Open a register shift before creating a sale.'],
            ]);
        }
        if ((int) $registerShift->cashier_id !== (int) $request->user()->id) {
            abort(403, 'This register shift belongs to another cashier.');
        }
        if ($registerShift->status !== 'open') {
            $occurredAtForShift = !empty($data['occurred_at']) ? \Carbon\Carbon::parse($data['occurred_at']) : now();
            if ($occurredAtForShift->lt($registerShift->opened_at) ||
                ($registerShift->closed_at && $occurredAtForShift->gt($registerShift->closed_at))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'register_shift' => ['Offline sale time is outside the referenced shift.'],
                ]);
            }
        }

        if (!empty($data['customer_id'])) {
            $customer = \App\Models\Customer::query()->findOrFail((int) $data['customer_id']);
            if ($customer->branch_id && (int) $customer->branch_id !== $branchId) {
                abort(422, 'Selected customer belongs to a different branch.');
            }
        }
        if (!empty($data['vendor_id'])) {
            $vendor = \App\Models\Vendor::query()->findOrFail((int) $data['vendor_id']);
            if ($vendor->branch_id && (int) $vendor->branch_id !== $branchId) {
                abort(422, 'Selected vendor belongs to a different branch.');
            }
        }

        if (!empty($data['salesman_id'])) {
            $this->assertUserCanBeAssignedToBranch($request, $branches, (int) $data['salesman_id'], $branchId, 'salesman');
        } else {
            $data['salesman_id'] = auth()->id(); // default to current user if not provided
        }
        if (!empty($data['delivery_boy_id'])) {
            $this->assertUserCanBeAssignedToBranch($request, $branches, (int) $data['delivery_boy_id'], $branchId, 'delivery');
        }

        $productBranches->assertProductsBelongToBranch(collect($data['items'])->pluck('product_id'), $branchId);

        return DB::transaction(function () use ($data, $branchId, $registerShift) {
            // Resolve invoice sequence service once per transaction.
            $invoiceSequencer = app(InvoiceSequenceService::class);

            // totals
            $subtotal = collect($data['items'])->sum(function ($i) {
                $qty   = (float)($i['quantity']      ?? 0);
                $price = (float)($i['price']         ?? 0);
                $pct   = (float)($i['discount_pct']  ?? 0);   // 0–100

                $pct   = max(0, min(100, $pct));              // clamp
                $line  = $qty * $price;
                $line -= $line * ($pct / 100);                // apply % off

                return $line;
            });
            $discount = (float)($data['discount'] ?? 0);
            $tax      = (float)($data['tax'] ?? 0);
            $delivery      = (float)($data['delivery'] ?? 0);
            $total    = round($subtotal - $discount + $tax + $delivery, 2);

            // Offline-sync: preserve the ORIGINAL sale time (handover doc
            // §1.3). occurred_at is the on-device timestamp captured the
            // moment the cashier pressed "Save Sale" while offline. When
            // present, both invoice_date AND the invoice number's date
            // prefix follow it (recommended option), so an offline Monday
            // sale still posts, numbers, and reconciles as a Monday sale
            // even if it's synced on Wednesday — keeping that day's
            // day-book/cashbook accurate. Falls back to "now" for normal
            // online sales (unchanged behaviour).
            $occurredAt = !empty($data['occurred_at']) ? \Carbon\Carbon::parse($data['occurred_at']) : now();

            // Allocate a branch-local daily invoice number inside this transaction.
            // InvoiceSequenceService uses a row-level lock on invoice_sequences so
            // concurrent sales in the same branch serialise safely here.
            $invoiceNo = $invoiceSequencer->allocate($branchId, $occurredAt);

            // create sale header
            try {
                $sale = Sale::create([
                    'invoice_no'         => $invoiceNo,
                    'offline_invoice_no' => $data['offline_invoice_no'] ?? null,
                    'client_ref'         => $data['client_ref'] ?? null,
                    'invoice_date' => $occurredAt->toDateString(),
                    'customer_id' => $data['customer_id'] ?? null,
                    'vendor_id'   => $data['vendor_id'] ?? null,
                    'salesman_id' => $data['salesman_id'] ?? null,
                    'delivery_boy_id' => $data['delivery_boy_id'] ?? null,
                    'created_by'  => auth()->id(),
                    'branch_id'   => $branchId,
                    'register_shift_id' => $registerShift->id,
                    'subtotal'    => round($subtotal, 2),
                    'discount'    => round($discount, 2),
                    'tax'         => round($tax, 2),
                    'delivery'    => round($delivery, 2),
                    'total'       => $total,
                    'status'      => 'pending',
                    'meta'        => $data['meta'] ?? [],
                    'sale_type' => $data['sale_type'] ?? 'dine_in',
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    // ── Path 1: client_ref collision → idempotent replay ──────────────
                    // Two concurrent sync attempts for the same queued sale can both
                    // slip past the pre-transaction client_ref check above before
                    // either has committed. The DB UNIQUE constraint on client_ref is
                    // the real stop; recover by returning the row the other request
                    // just created, identical to the pre-transaction idempotent path.
                    if (!empty($data['client_ref'])) {
                        $existing = Sale::where('client_ref', $data['client_ref'])->first();
                        if ($existing) {
                            return ApiResponse::success([
                                'sale'               => $existing->fresh(['items', 'payments']),
                                'receipts'           => null,
                                'already_existed'    => true,
                                'invoice_no'         => $existing->invoice_no,
                                'offline_invoice_no' => $existing->offline_invoice_no,
                            ], 'Sale already recorded (idempotent replay)');
                        }
                    }

                    // ── Path 2: offline_invoice_no collision → 409 Conflict ───────────
                    // The UNIQUE constraint fired on offline_invoice_no, not client_ref.
                    // A different sale already holds this offline reference number.
                    // Root causes: two POS terminals sharing the same register_code, or
                    // the device's offline sequence counter was reset after an app
                    // reinstall / data clear. This is a genuine collision that a human
                    // must reconcile. Return 409 with a structured error so Flutter
                    // dead-letters the queued item immediately — without this, Flutter
                    // treats the 500 as retryable and hammers the endpoint six times
                    // before giving up with a cryptic message.
                    if (!empty($data['offline_invoice_no'])) {
                        $colliding = Sale::where('offline_invoice_no', $data['offline_invoice_no'])->first();
                        if ($colliding) {
                            // Defensive: if the colliding row actually has the same
                            // client_ref, Path 1 above should have caught it already;
                            // handle it here too so we never 409 a true idempotent replay.
                            if (
                                !empty($data['client_ref'])
                                && (string) $colliding->client_ref === (string) $data['client_ref']
                            ) {
                                return ApiResponse::success([
                                    'sale'               => $colliding->fresh(['items', 'payments']),
                                    'receipts'           => null,
                                    'already_existed'    => true,
                                    'invoice_no'         => $colliding->invoice_no,
                                    'offline_invoice_no' => $colliding->offline_invoice_no,
                                ], 'Sale already recorded (idempotent replay)');
                            }

                            return response()->json([
                                'success' => false,
                                'message' => 'Offline invoice number already used by a different sale. '
                                    . 'Two terminals may share the same register code, or the offline '
                                    . 'sequence was reset on this device (reinstall / data clear). '
                                    . 'This queued sale must be reviewed and manually reconciled.',
                                'code'    => 'OFFLINE_INVOICE_NO_COLLISION',
                                'data'    => [
                                    'offline_invoice_no'     => $data['offline_invoice_no'],
                                    'conflicting_invoice_no' => $colliding->invoice_no,
                                ],
                            ], 409);
                        }
                    }
                }

                throw $e;
            }

            // Stamp created_at to the original offline sale time too (not
            // just invoice_date), so anything reading created_at directly —
            // index()'s date_from/date_to filters, default ordering, etc. —
            // reflects the real sale time rather than the sync time.
            if (!empty($data['occurred_at'])) {
                $sale->created_at = $occurredAt;
                $sale->saveQuietly();
            }

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
                $qty       = (float)$item['quantity'];
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
                    'register_shift_id' => $sale->register_shift_id,
                    'sale_id' => $sale->id,
                    'received_at' => $payment['paid_at'] ?? now()->toDateString(),
                    'method'      => $payment['method'] ?? 'cash',
                    'amount'      => (float)$payment['amount'],
                    // Preserve the user's reference (KNET id, approval code, …);
                    // document wording stays in the memo only.
                    'reference'   => $payment['reference'] ?? null,
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
                'sale'               => $sale->fresh(['items', 'payments']),
                'receipts'           => $createdReceipts ?: null,
                'invoice_no'         => $sale->invoice_no,
                'offline_invoice_no' => $sale->offline_invoice_no,
            ], 'Sale created and posted to ledger');
        });
    }



    // Helper to update status
    protected function updateSaleStatus(Sale $sale)
    {
        $paid = $sale->payments()->sum('amount');
        if ($sale->total > 0 && $paid >= $sale->total) {
            $sale->update(['status' => 'paid']);
        } elseif ($paid > 0) {
            $sale->update(['status' => 'partial']);
        } else {
            $sale->update(['status' => 'pending']);
        }
    }

    public function updateDeliveryBoy(Request $request, BranchContextService $branches, $id)
    {
        $data = $request->validate([
            'delivery_boy_id' => 'nullable|exists:users,id'
        ]);

        $sale = Sale::findOrFail($id);
        $branches->assertCanAccessBranch($request, $sale->branch_id ? (int) $sale->branch_id : null);
        if (!empty($data['delivery_boy_id'])) {
            $this->assertUserCanBeAssignedToBranch($request, $branches, (int) $data['delivery_boy_id'], (int) $sale->branch_id, 'delivery');
        }

        $oldDeliveryBoyId = $sale->delivery_boy_id ? (int) $sale->delivery_boy_id : null;
        $newDeliveryBoyId = !empty($data['delivery_boy_id']) ? (int) $data['delivery_boy_id'] : null;

        $sale->update(['delivery_boy_id' => $newDeliveryBoyId]);

        app(\App\Services\DeliveryBoyLedgerService::class)->postSaleDeliveryBoyChange(
            $sale->fresh(),
            $oldDeliveryBoyId,
            $newDeliveryBoyId,
            now()->toDateString()
        );

        return ApiResponse::success($sale->fresh(), 'Delivery boy updated successfully');
    }

    public function update(Request $request, BranchContextService $branches, $id)
    {
        // Only allow updating discount & tax from this endpoint (as per your UI)
        $data = $request->validate([
            'discount' => 'nullable|numeric|min:0',
            'tax'      => 'nullable|numeric|min:0',
            'delivery'      => 'nullable|numeric|min:0',
        ]);

        $sale = Sale::with(['items', 'payments'])->findOrFail($id);
        $branches->assertCanAccessBranch($request, $sale->branch_id ? (int) $sale->branch_id : null);

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

    private function assertUserCanBeAssignedToBranch(Request $request, BranchContextService $branches, int $userId, int $branchId, ?string $role = null): void
    {
        $user = User::query()->with('roles:id,name')->findOrFail($userId);
        $branchRoles = app(BranchRoleService::class);

        if ($role && !$branchRoles->userHasBaseRole($user, $role)) {
            abort(422, ucfirst($role) . ' role is required for selected user.');
        }

        if ((int) ($user->branch_id ?? 0) !== (int) $branchId) {
            abort(422, 'Selected user belongs to a different branch.');
        }

        $branches->assertCanAccessBranch($request, $branchId);
    }

    /**
     * True if the given QueryException is a unique-constraint violation
     * (MySQL error 1062 / SQLSTATE 23000). Used to catch the client_ref
     * race in store() without swallowing other DB errors.
     */
    private function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        return (string) $e->getCode() === '23000' || (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    /**
     * Offline-sync reconciliation (handover doc §1.4).
     *
     * Lets the device check "did this sale actually go through?" after an
     * ambiguous sync result (e.g. the POST /sales request was sent and the
     * server saved it, but the connection dropped before the response came
     * back). The device sends every client_ref it's unsure about and gets
     * back which ones exist server-side and which are safe to retry.
     */
    public function verifyBatch(Request $request)
    {
        $data = $request->validate([
            'client_refs'   => 'required|array|min:1',
            'client_refs.*' => 'uuid',
        ]);

        $refs = collect($data['client_refs'])->unique()->values();

        $found = Sale::whereIn('client_ref', $refs)
            ->get(['id', 'client_ref', 'invoice_no', 'offline_invoice_no', 'total'])
            ->map(fn ($sale) => [
                'client_ref'         => $sale->client_ref,
                'id'                 => $sale->id,
                'invoice_no'         => $sale->invoice_no,
                'offline_invoice_no' => $sale->offline_invoice_no,
                'total'              => (float) $sale->total,
            ])
            ->values();

        $foundRefs = $found->pluck('client_ref');
        $missing   = $refs->diff($foundRefs)->values();

        return ApiResponse::success([
            'found'   => $found,
            'missing' => $missing,
        ]);
    }
}
