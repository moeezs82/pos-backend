<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Purchase;
use App\Models\PurchaseClaim;
use App\Models\StockMovement;
use App\Services\AccountingService;
use App\Services\CashSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseClaimController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseClaim::with([
            'purchase:id,invoice_no,vendor_id,branch_id,subtotal,total',
            'purchase.vendor:id,first_name',
            'branch:id,name'
        ])->withSum('receipts as received_total', 'amount');

        if ($request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->vendor_id) {
            $query->where('vendor_id', $request->vendor_id);
        }
        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('claim_no', 'like', "%$search%")
                    ->orWhereHas('purchase', fn($p) => $p->where('invoice_no', 'like', "%$search%"));
                // ->orWhereHas('purchase.vendor', fn($v) => $v->where('first_name', 'like', "%$search%"));
            });
        }

        return ApiResponse::success($query->orderByDesc('id')->paginate(15));
    }

    public function show($id)
    {
        $claim = PurchaseClaim::with([
            'purchase:id,invoice_no,vendor_id,branch_id,subtotal,total',
            'purchase.vendor:id,first_name,email,phone',
            'branch:id,name',
            'items.product:id,name,sku',
            'receipts:id,purchase_claim_id,amount,method,reference,received_at,created_at'
        ])->findOrFail($id);

        return ApiResponse::success($claim);
    }

    public function store(Request $request, AccountingService $accounting, CashSyncService $cashSync, \App\Services\VendorPaymentService $vps)
    {
        $data = $request->validate([
            'purchase_id' => ['required', 'integer', 'exists:purchases,id'],
            'type'        => ['nullable', 'in:shortage,damaged,wrong_item,expired,other'],
            'reason'      => ['nullable', 'string'],
            'items'       => ['required', 'array', 'min:1'],

            // Ensure item exists AND belongs to this purchase_id (checked below too)
            'items.*.purchase_item_id' => ['required', 'integer'],
            'items.*.quantity'         => ['required', 'integer', 'min:1'],
            'items.*.affects_stock'    => ['nullable', 'boolean'], // default inferred by type
            'items.*.remarks'          => ['nullable', 'string'],
            'items.*.batch_no'         => ['nullable', 'string'],
            'items.*.expiry_date'      => ['nullable', 'date'],

            'approve_now'          => ['nullable', 'boolean'],
            'receipt.amount'       => ['nullable', 'numeric', 'min:0.01'],
            'receipt.method'       => ['nullable', 'string'],
            'receipt.reference'    => ['nullable', 'string'],
            'receipt.received_at'  => ['nullable', 'date'],
        ]);

        return DB::transaction(function () use ($data, $request, $accounting, $cashSync, $vps) {
            // Lock the purchase header
            $purchase = \App\Models\Purchase::query()
                ->where('id', $data['purchase_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $type = $data['type'] ?? 'other';

            // Fetch ONLY the items involved (ONE query)
            $requestedIds = collect($data['items'])->pluck('purchase_item_id')->unique()->values();
            $purchaseItems = \App\Models\PurchaseItem::query()
                ->where('purchase_id', $purchase->id)
                ->whereIn('id', $requestedIds)
                ->select('id', 'purchase_id', 'product_id', 'quantity', 'price')
                ->get()
                ->keyBy('id');

            // Ensure all requested items belong to this purchase
            if ($purchaseItems->count() !== $requestedIds->count()) {
                return \App\Http\Response\ApiResponse::error(
                    'One or more purchase_item_id do not belong to this purchase.',
                    422
                );
            }

            // Sum already claimed qty for these items in ONE query
            // If you have statuses, exclude rejected/cancelled here as needed.
            $alreadyClaimed = DB::table('purchase_claim_items as pci')
                ->join('purchase_claims as pc', 'pc.id', '=', 'pci.purchase_claim_id')
                ->where('pc.purchase_id', $purchase->id)
                // ->whereNotIn('pc.status', ['rejected','cancelled']) // uncomment if applicable
                ->whereIn('pci.purchase_item_id', $requestedIds)
                ->groupBy('pci.purchase_item_id')
                ->pluck(DB::raw('SUM(pci.quantity)'), 'pci.purchase_item_id'); // map: id => claimed_qty

            // Single validation + preparation pass
            $violations = [];
            $prepared = []; // for bulk insert
            $byProductDelta = []; // product_id => qty to decrement from stock if affects_stock=true
            $subtotal = 0.0;

            foreach ($data['items'] as $row) {
                $pi = $purchaseItems[$row['purchase_item_id']];
                $sold = (int) $pi->quantity;                 // purchased qty
                $prev = (int) ($alreadyClaimed[$pi->id] ?? 0);
                $remaining = max($sold - $prev, 0);

                $req = (int) $row['quantity'];
                if ($req > $remaining) {
                    $violations[] = "Item #{$pi->id}: requested {$req} exceeds remaining {$remaining} (purchased {$sold}, claimed {$prev}).";
                    continue;
                }
                if ($req <= 0) continue;

                // default affects_stock: shortage = false; others = true (can be overridden per-line)
                $affects = array_key_exists('affects_stock', $row)
                    ? (bool)$row['affects_stock']
                    : ($type !== 'shortage');

                $price = (float) $pi->price; // use purchase cost
                $line  = $req * $price;
                $subtotal += $line;

                $prepared[] = [
                    'purchase_item_id' => $pi->id,
                    'product_id'       => $pi->product_id,
                    'quantity'         => $req,
                    'price'            => $price,
                    'total'            => $line,
                    'affects_stock'    => $affects,
                    'remarks'          => $row['remarks'] ?? null,
                    'batch_no'         => $row['batch_no'] ?? null,
                    'expiry_date'      => $row['expiry_date'] ?? null,
                ];

                if ($affects) {
                    // We are CLAIMING against stock => reduce branch stock
                    $byProductDelta[$pi->product_id] = ($byProductDelta[$pi->product_id] ?? 0) + $req;
                }
            }

            if (!empty($violations)) {
                return \App\Http\Response\ApiResponse::error([
                    'message' => 'Purchase claim validation failed.',
                    'details' => $violations,
                ], 422);
            }
            if (empty($prepared)) {
                return \App\Http\Response\ApiResponse::error('No valid claim lines.', 422);
            }

            // Create claim header
            $claim = \App\Models\PurchaseClaim::create([
                'claim_no'    => 'PCL-' . now()->format('YmdHis'),
                'purchase_id' => $purchase->id,
                'vendor_id'   => $purchase->vendor_id,
                'branch_id'   => $purchase->branch_id,
                'type'        => $type,
                'reason'      => $data['reason'] ?? null,
                'status'      => 'pending',
                'subtotal'    => $subtotal,
                'tax'         => 0,
                'total'       => $subtotal, // add tax if needed
                'created_by'  => optional($request->user())->id,
            ]);

            // Bulk insert claim items (ONE query)
            $rows = array_map(function ($line) use ($claim) {
                return [
                    'purchase_claim_id' => $claim->id,
                    'purchase_item_id'  => $line['purchase_item_id'],
                    'product_id'        => $line['product_id'],
                    'quantity'          => $line['quantity'],
                    'price'             => $line['price'],
                    'total'             => $line['total'],
                    'affects_stock'     => $line['affects_stock'],
                    'remarks'           => $line['remarks'],
                    'batch_no'          => $line['batch_no'],
                    'expiry_date'       => $line['expiry_date'],
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ];
            }, $prepared);
            DB::table('purchase_claim_items')->insert($rows);

            $approveNow = (bool) ($request->boolean('approve_now'));
            if ($approveNow) {
                $claim->update([
                    'status'      => 'approved',
                    'approved_by' => optional($request->user())->id,
                    'approved_at' => now(),
                ]);
                // 1) Stock movements for stock-affecting items (RETURN/DECREMENT)
                foreach ($claim->items as $item) {
                    if (! $item->affects_stock) continue;

                    $this->ensureStockRowAndDecrement(
                        productId: $item->product_id,
                        branchId: $claim->branch_id,
                        qtyToDecrement: (int) $item->quantity,
                        reference: $claim->claim_no
                    );
                }
                // 3) Post GL (AP debit; Inventory / PurchaseReturns credits)
                $this->postClaimAccounting($claim, $accounting, $request, $vps);
            }

            return \App\Http\Response\ApiResponse::success(
                $claim->load(['items.product:id,name,sku'])
            );
        });
    }

    // -------------------- Helpers (private/protected) --------------------

    /**
     * Ensure a product_stock row exists for product+branch, lock it, decrement quantity,
     * and insert a stock movement record (negative quantity).
     *
     * @param int $productId
     * @param int $branchId
     * @param int $qtyToDecrement
     * @param string $reference
     * @return void
     */
    protected function ensureStockRowAndDecrement(int $productId, int $branchId, int $qtyToDecrement, string $reference): void
    {
        // Ensure exists (one quick exists check + insert if missing)
        $exists = DB::table('product_stocks')
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->exists();

        if (! $exists) {
            DB::table('product_stocks')->insert([
                'product_id' => $productId,
                'branch_id'  => $branchId,
                'quantity'   => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Lock the row before updating
        DB::table('product_stocks')
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        // Decrement (allow negative)
        DB::table('product_stocks')
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->decrement('quantity', $qtyToDecrement);

        // Insert movement
        DB::table('stock_movements')->insert([
            'product_id' => $productId,
            'branch_id'  => $branchId,
            'type'       => 'purchase_claim',
            'quantity'   => -1 * (int)$qtyToDecrement,
            'reference'  => $reference,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Post GL entries for a claim approval.
     * Debits AP for full claim total and credits Inventory / PurchaseReturns accordingly.
     *
     * @param \App\Models\PurchaseClaim $claim
     * @param \App\Services\AccountingService $accounting
     * @param \Illuminate\Http\Request|null $request
     * @throws \Exception on imbalance
     */
    // protected function postClaimAccounting(\App\Models\PurchaseClaim $claim, \App\Services\AccountingService $accounting, ?\Illuminate\Http\Request $request = null): void
    // {
    //     // Compute amounts
    //     $stockAmount = 0.0;
    //     $nonStockAmount = 0.0;

    //     foreach ($claim->items as $it) {
    //         $lineTotal = isset($it->total) ? (float)$it->total : ((float)$it->price * (int)$it->quantity);
    //         if ($it->affects_stock) {
    //             $stockAmount += $lineTotal;
    //         } else {
    //             $nonStockAmount += $lineTotal;
    //         }
    //     }

    //     $stockAmount = round($stockAmount, 2);
    //     $nonStockAmount = round($nonStockAmount, 2);
    //     $claimTotal = round((float)$claim->total, 2);

    //     // Accounts from config (change to match your COA)
    //     $apAccount        = config('accounts.ap_account', '2000');
    //     $inventoryAccount = config('accounts.inventory_account', '1400');
    //     $returnsAccount   = config('accounts.purchase_returns_account', '4000');

    //     $lines = [];

    //     // Debit AP (reduce liability)
    //     $lines[] = [
    //         'account_code' => $apAccount,
    //         'debit' => $claimTotal,
    //         'credit' => 0,
    //     ];

    //     if ($stockAmount > 0) {
    //         $lines[] = [
    //             'account_code' => $inventoryAccount,
    //             'debit' => 0,
    //             'credit' => $stockAmount,
    //         ];
    //     }

    //     if ($nonStockAmount > 0) {
    //         $lines[] = [
    //             'account_code' => $returnsAccount,
    //             'debit' => 0,
    //             'credit' => $nonStockAmount,
    //         ];
    //     }

    //     // Sanity check
    //     $debitSum = array_sum(array_map(fn($l) => (float)$l['debit'], $lines));
    //     $creditSum = array_sum(array_map(fn($l) => (float)$l['credit'], $lines));
    //     $diff = round($debitSum - $creditSum, 2);

    //     if (abs($diff) > 0.05) {
    //         throw new \Exception("Claim accounting mismatch: debits ($debitSum) != credits ($creditSum)");
    //     }

    //     $accounting->post(
    //         branchId: $claim->branch_id,
    //         memo: "Purchase Claim {$claim->claim_no} approved for Purchase {$claim->purchase_id}",
    //         reference: $claim,
    //         lines: $lines,
    //         entryDate: $claim->approved_at ?? now()->toDateString(),
    //         userId: optional($request?->user())->id
    //     );
    // }
    protected function postClaimAccounting(
        \App\Models\PurchaseClaim $claim,
        \App\Services\AccountingService $accounting,
        ?\Illuminate\Http\Request $request = null,
        \App\Services\VendorPaymentService $vps
    ): void {
        // Compute per-bucket totals
        $stockAmount    = 0.0;
        $nonStockAmount = 0.0;

        foreach ($claim->items as $it) {
            $lineTotal = isset($it->total)
                ? (float) $it->total
                : ((float) $it->price * (int) $it->quantity);

            if ($it->affects_stock) $stockAmount += $lineTotal;
            else                     $nonStockAmount += $lineTotal;
        }

        $stockAmount    = round($stockAmount, 2);
        $nonStockAmount = round($nonStockAmount, 2);
        $claimTotal     = round((float) $claim->total, 2);

        // Accounts (adjust via config/accounts.php)
        $apAccount        = config('accounts.ap_account', '2000');
        $inventoryAccount = config('accounts.inventory_account', '1400');
        $returnsAccount   = config('accounts.purchase_returns_account', '4000');

        $lines = [];

        // DR AP (reduce liability) — full claim total
        $lines[] = [
            'account_code' => $apAccount,
            'debit'        => $claimTotal,
            'credit'       => 0,
        ];

        // CR Inventory for stock portion
        if ($stockAmount > 0) {
            $lines[] = [
                'account_code' => $inventoryAccount,
                'debit'        => 0,
                'credit'       => $stockAmount,
            ];
        }

        // CR Purchase Returns (or relevant contra/expense) for non-stock portion
        if ($nonStockAmount > 0) {
            $lines[] = [
                'account_code' => $returnsAccount,
                'debit'        => 0,
                'credit'       => $nonStockAmount,
            ];
        }

        // Sanity check
        $debitSum  = array_sum(array_column($lines, 'debit'));
        $creditSum = array_sum(array_column($lines, 'credit'));
        if (round($debitSum - $creditSum, 2) !== 0.00) {
            throw new \Exception("Claim accounting mismatch: debits ($debitSum) != credits ($creditSum)");
        }

        // Post JE
        $accounting->post(
            branchId: $claim->branch_id,
            memo: "Purchase Claim {$claim->claim_no} approved (Purchase #{$claim->purchase_id})",
            reference: $claim,
            lines: $lines,
            entryDate: ($claim->approved_at ?? now())->toDateString(),
            userId: optional($request?->user())->id
        );
        if ($claim->vendor_id) {
            $vps->create([
                'vendor_id' => $claim->vendor_id,
                'branch_id' => $claim->branch_id,
                'method' => 'cash',
                'amount' => $stockAmount,
                'reference' => "Claim receipt approve for Purchase Invoice # " . $claim->purchase?->invoice_no
            ], false);
        }
    }

    /**
     * Post accounting for a single receipt amount (DR Cash/Bank, CR PurchaseReturns or AP).
     *
     * @param \App\Models\PurchaseClaim $claim
     * @param float $amount
     * @param string $method
     * @param \App\Services\AccountingService $accounting
     * @param \App\Services\CashSyncService $cashSync
     * @param \Illuminate\Http\Request|null $request
     * @param bool $creditAp If true, credit AP; otherwise credit Purchase Returns (default false)
     * @return void
     */
    protected function postReceiptAccounting(\App\Models\PurchaseClaim $claim, float $amount, string $method, \App\Services\AccountingService $accounting, \App\Services\CashSyncService $cashSync, ?\Illuminate\Http\Request $request = null, bool $creditAp = false): void
    {
        $cashAccount = $cashSync->mapMethodToAccount($method, $claim->branch_id);
        $apAccount = config('accounts.ap_account', '2000');
        $returnsAccount = config('accounts.purchase_returns_account', '4000');

        $creditAccount = $creditAp ? $apAccount : $returnsAccount;

        $glLines = [
            ['account_code' => $cashAccount->code, 'debit' => round($amount, 2), 'credit' => 0],
            ['account_code' => $creditAccount,      'debit' => 0,                  'credit' => round($amount, 2)],
        ];

        $accounting->post(
            branchId: $claim->branch_id,
            memo: "Purchase Claim {$claim->claim_no} receipt ({$method})",
            reference: $claim,
            lines: $glLines,
            entryDate: $request?->input('received_at') ?? now()->toDateString(),
            userId: optional($request?->user())->id
        );
    }

    // -------------------- Updated actions --------------------

    /**
     * Approve a purchase claim (refactored to use helpers).
     */
    // public function approve($id, Request $request, \App\Services\AccountingService $accounting, \App\Services\CashSyncService $cashSync)
    // {
    //     return DB::transaction(function () use ($id, $request, $accounting, $cashSync) {
    //         $claim = PurchaseClaim::with(['items'])
    //             ->lockForUpdate()
    //             ->findOrFail($id);

    //         if ($claim->status !== 'pending') {
    //             return ApiResponse::error("Only pending claims can be approved.", 422);
    //         }

    //         // Process stock-affecting items
    //         foreach ($claim->items as $item) {
    //             if (! $item->affects_stock) continue;

    //             $this->ensureStockRowAndDecrement($item->product_id, $claim->branch_id, (int)$item->quantity, $claim->claim_no);
    //         }

    //         $claim->update([
    //             'status'      => 'approved',
    //             'approved_by' => optional($request->user())->id,
    //             'approved_at' => now(),
    //         ]);

    //         // Create receipt if provided (storeReceipt returns ['receipt', 'new_total', 'this_amount'])
    //         $receiptResult = null;
    //         try {
    //             $receiptResult = $this->storeReceipt($claim, $request);
    //         } catch (\Illuminate\Validation\ValidationException $ve) {
    //             // if validation fails for receipt amount (e.g., no amount provided) we simply ignore here
    //             // storeReceipt throws if amount provided but greater than left; but if no amount was sent, it will fail validation.
    //             // To keep previous behavior (no-op when no amount), we treat validation exception as no-op.
    //             $receiptResult = null;
    //         }

    //         // Post main claim accounting (AP reduction / inventory & returns credits)
    //         $this->postClaimAccounting($claim, $accounting, $request);

    //         // If receipt was created, post receipt accounting for this receipt amount
    //         if (is_array($receiptResult) && isset($receiptResult['this_amount']) && $receiptResult['this_amount'] > 0) {
    //             $this->postReceiptAccounting($claim, $receiptResult['this_amount'], $receiptResult['receipt']->method ?? 'cash', $accounting, $cashSync, $request);
    //         }

    //         return ApiResponse::success($claim->fresh(['items.product:id,name,sku']));
    //     });
    // }
    public function approve(
        $id,
        Request $request,
        \App\Services\AccountingService $accounting,
        \App\Services\VendorPaymentService $vps
    ) {
        return DB::transaction(function () use ($id, $request, $accounting, $vps) {
            $claim = PurchaseClaim::with(['items', 'purchase:id,vendor_id,branch_id,invoice_no'])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($claim->status !== 'pending') {
                return ApiResponse::error('Only pending claims can be approved.', 422);
            }

            // 1) Stock movements for stock-affecting items (RETURN/DECREMENT)
            foreach ($claim->items as $item) {
                if (! $item->affects_stock) continue;

                $this->ensureStockRowAndDecrement(
                    productId: $item->product_id,
                    branchId: $claim->branch_id,
                    qtyToDecrement: (int) $item->quantity,
                    reference: $claim->claim_no
                );
            }

            // 2) Mark approved
            $claim->update([
                'status'      => 'approved',
                'approved_by' => optional($request->user())->id,
                'approved_at' => now(),
            ]);

            // 3) Post GL (AP debit; Inventory / PurchaseReturns credits)
            $this->postClaimAccounting($claim, $accounting, $request, $vps);

            // 4) Done — no receipt creation/posting
            return ApiResponse::success(
                $claim->fresh(['items.product:id,name,sku'])
            );
        });
    }

    /**
     * Create a receipt for an existing claim (endpoint).
     */
    public function receipt(Request $request, $id, \App\Services\AccountingService $accounting, \App\Services\CashSyncService $cashSync)
    {
        $request->validate([
            'amount'      => 'required|numeric|min:0.01',
            'method'      => 'nullable|string',
            'reference'   => 'nullable|string',
            'received_at' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($request, $id, $accounting, $cashSync) {
            /** @var \App\Models\PurchaseClaim $claim */
            $claim = PurchaseClaim::query()
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            // create receipt record(s) and get new total + this amount
            $receiptResult = $this->storeReceipt($claim, $request);
            $thisAmount = $receiptResult['this_amount'];
            $newTotal = $receiptResult['new_total'];

            // post accounting for this receipt
            $this->postReceiptAccounting($claim, $thisAmount, $receiptResult['receipt']->method ?? 'cash', $accounting, $cashSync, $request);

            return \App\Http\Response\ApiResponse::success([
                'claim'            => $claim->fresh(['receipts']),
                'received_total'   => round((float)$newTotal, 2),
                'receivable_left'  => round(max(0, (float)$claim->total - (float)$newTotal), 2),
            ], 'Receipt posted');
        });
    }

    /**
     * Create a PurchaseClaimReceipt for the given claim using data from the request.
     * Returns array: ['receipt' => PurchaseClaimReceipt, 'new_total' => float, 'this_amount' => float]
     */
    protected function storeReceipt(\App\Models\PurchaseClaim $claim, \Illuminate\Http\Request $request): array
    {
        // We accept the same validation as before, but to allow callers that didn't intend to send a receipt
        // (e.g. approve() without amount) the caller should catch ValidationException as done in approve().
        $data = $request->validate([
            'amount'      => 'required|numeric|min:0.01',
            'method'      => 'nullable|string',
            'reference'   => 'nullable|string',
            'received_at' => 'nullable|date',
        ]);

        $amount = round((float)$data['amount'], 2);

        $receivedSoFar = \App\Models\PurchaseClaimReceipt::where('purchase_claim_id', $claim->id)->sum('amount');
        $left = max(0, (float)$claim->total - (float)$receivedSoFar);

        if ($amount > $left + 0.00001) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amount' => ["Receipt {$amount} exceeds receivable left {$left}"]
            ]);
        }

        $receipt = \App\Models\PurchaseClaimReceipt::create([
            'purchase_claim_id' => $claim->id,
            'amount'            => $amount,
            'method'            => $data['method'] ?? 'cash',
            'reference'         => $data['reference'] ?? null,
            'received_at'       => $data['received_at'] ?? now()->toDateString(),
            'created_by'        => optional($request->user())->id,
        ]);

        $newTotal = \App\Models\PurchaseClaimReceipt::where('purchase_claim_id', $claim->id)->sum('amount');

        return [
            'receipt' => $receipt,
            'new_total' => round((float)$newTotal, 2),
            'this_amount' => $amount,
        ];
    }


    public function reject($id, Request $request)
    {
        $claim = PurchaseClaim::findOrFail($id);

        if ($claim->status !== 'pending') {
            return ApiResponse::error("Only pending claims can be rejected.", 422);
        }

        $claim->update([
            'status'      => 'rejected',
            'rejected_by' => optional($request->user())->id,
            'rejected_at' => now(),
        ]);

        return ApiResponse::success($claim);
    }

    public function close($id, Request $request)
    {
        $claim = PurchaseClaim::findOrFail($id);

        if (!in_array($claim->status, ['approved', 'rejected'])) {
            return ApiResponse::error("Only approved or rejected claims can be closed.", 422);
        }

        $claim->update([
            'status'    => 'closed',
            'closed_by' => optional($request->user())->id,
            'closed_at' => now(),
        ]);

        return ApiResponse::success($claim);
    }
}
