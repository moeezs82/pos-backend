<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\CashTransaction;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnRefund;
use App\Models\StockMovement;
use App\Services\CashSyncService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleReturnController extends Controller
{
    public function index(Request $request)
    {
        $query = SaleReturn::with(['sale:id,invoice_no,customer_id,branch_id', 'sale.customer:id,first_name,last_name', 'sale.branch:id,name'])
                ->withSum('refunds as refund_total', 'amount');

        if ($request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->customer_id) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('return_no', 'like', "%$search%")
                    ->orWhereHas('sale', fn($p) => $p->where('invoice_no', 'like', "%$search%"));
                    // ->orWhereHas('sale.customer', fn($v) => $v->where('first_name', 'like', "%$search%"));
            });
        }

        return ApiResponse::success($query->orderByDesc('id')->paginate(15));
    }

    public function show($id)
    {
        $return = SaleReturn::with([
            'sale:id,invoice_no,customer_id,branch_id,subtotal,total',
            'sale.customer:id,first_name,last_name,email,phone',
            'sale.branch:id,name',
            'items.product:id,name,sku',
            'refunds:id,sale_return_id,amount'
        ])->findOrFail($id);

        return ApiResponse::success($return);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'items'   => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer'],
            'items.*.quantity'     => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string'],
            // New: approve + refund on create
            'approve_now'          => ['nullable', 'boolean'],
            'refund.amount'        => ['nullable', 'numeric', 'min:0.01'],
            'refund.method'        => ['nullable', 'string'],
            'refund.reference'     => ['nullable', 'string'],
            'refund.refunded_at'   => ['nullable', 'date'],
        ]);

        $sale = Sale::where('id', $data['sale_id'])->lockForUpdate()->firstOrFail();
        return DB::transaction(function () use ($data, $sale, $request) {
            // Lock sale header

            // Only fetch the items we need (ONE query)
            $requestedIds = collect($data['items'])->pluck('sale_item_id')->unique()->values();
            $saleItems = SaleItem::query()
                ->where('sale_id', $sale->id)
                ->whereIn('id', $requestedIds)
                ->select('id', 'sale_id', 'product_id', 'quantity', 'price')
                ->get()->keyBy('id');

            // Ensure all requested items belong to this sale
            if ($saleItems->count() !== $requestedIds->count()) {
                return ApiResponse::error('One or more sale_item_id do not belong to this sale.', 422);
            }

            // Sum already returned quantities in ONE query
            $alreadyReturned = DB::table('sale_return_items as sri')
                ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
                ->where('sr.sale_id', $sale->id)
                ->whereIn('sri.sale_item_id', $requestedIds)
                ->groupBy('sri.sale_item_id')
                ->pluck(DB::raw('SUM(sri.quantity)'), 'sri.sale_item_id'); // map: id => returned_qty

            // Single validation + preparation pass (no recomputation later)
            $violations = [];
            $prepared = [];        // per line: ['sale_item_id','product_id','qty','price','total']
            $byProductIncrements = []; // product_id => total qty to add back

            foreach ($data['items'] as $r) {
                $si = $saleItems[$r['sale_item_id']];
                $sold = (int)$si->quantity;
                $prev = (int)($alreadyReturned[$si->id] ?? 0);
                $remaining = max($sold - $prev, 0);
                $req = (int)$r['quantity'];

                if ($req > $remaining) {
                    $violations[] = "Item #{$si->id}: requested {$req} exceeds remaining {$remaining} (sold {$sold}, returned {$prev}).";
                    continue;
                }

                if ($req <= 0) continue;

                $lineTotal = $req * (float)$si->price;
                $prepared[] = [
                    'sale_item_id' => $si->id,
                    'product_id'   => $si->product_id,
                    'quantity'     => $req,
                    'price'        => $si->price,
                    'total'        => $lineTotal,
                ];
                $byProductIncrements[$si->product_id] = ($byProductIncrements[$si->product_id] ?? 0) + $req;
            }

            if (!empty($violations)) {
                return ApiResponse::error("Return validation failed.", 422, $violations);
            }

            if (empty($prepared)) {
                return ApiResponse::error('No valid return lines.', 422);
            }

            // Create return header
            $subtotal = array_sum(array_column($prepared, 'total'));
            $return = SaleReturn::create([
                'sale_id'     => $sale->id,
                'customer_id' => $sale->customer_id,
                'vendor_id'   => $sale->vendor_id,
                'branch_id'   => $sale->branch_id,
                'return_no'   => 'RET-' . now()->format('YmdHis'),
                'subtotal'    => $subtotal,
                'tax'         => 0,
                'total'       => $subtotal,
                'reason'      => $data['reason'] ?? null,
            ]);

            // Bulk insert items (ONE query)
            $rows = array_map(function ($line) use ($return) {
                return [
                    'sale_return_id' => $return->id,
                    'sale_item_id'   => $line['sale_item_id'],
                    'product_id'     => $line['product_id'],
                    'quantity'       => $line['quantity'],
                    'price'          => $line['price'],
                    'total'          => $line['total'],
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ];
            }, $prepared);
            DB::table('sale_return_items')->insert($rows);

            // Stock restore in ONE SQL using CASE..WHEN (no per-row increment)
            // (Falls back to per-row if your DB driver dislikes big CASE statements.)
            $productIds = array_keys($byProductIncrements);
            if (!empty($productIds)) {
                $case = collect($byProductIncrements)->map(function ($qty, $pid) {
                    return "WHEN {$pid} THEN {$qty}";
                })->implode(' ');
                DB::update("
                UPDATE product_stocks
                SET quantity = quantity + CASE product_id {$case} END
                WHERE branch_id = ? AND product_id IN (" . implode(',', $productIds) . ")
            ", [$sale->branch_id]);

                // Movements: insert in bulk (ONE query)
                $movementRows = [];
                foreach ($byProductIncrements as $pid => $qty) {
                    $movementRows[] = [
                        'product_id' => $pid,
                        'branch_id'  => $sale->branch_id,
                        'type'       => 'return',
                        'quantity'   => $qty,
                        'reference'  => $return->return_no,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                DB::table('stock_movements')->insert($movementRows);
            }
            // --- Approve now & immediate refund (optional) ---
            $approveNow = (bool) ($data['approve_now'] ?? false);
            $refundedTotal = 0.0;
            $refundableLeft = (float) $return->total;

            if ($approveNow) {
                $return->update(['status' => 'approved']);

                // If refund provided, validate and post to cashbook
                $refundAmount = (float) ($request->input('refund.amount') ?? 0);
                if ($refundAmount > 0) {
                    if ($refundAmount > $refundableLeft) {
                        return \App\Http\Response\ApiResponse::error(
                            "Refund {$refundAmount} exceeds refundable left {$refundableLeft}",
                            422
                        );
                    }

                    SaleReturnRefund::create([
                        'sale_return_id' => $return->id,
                        'amount'         => (float)$request->input('refund.amount'),
                        'method'         => $request->input('refund.method', 'cash'),
                        'reference'      => $request->input('refund.reference'),
                        'refunded_at'    => $request->input('refund.refunded_at'),
                        'created_by'     => optional($request->user())->id,
                    ]);
                }
            }

            return ApiResponse::success($return->load('items'));
        });
    }
    // Approve Return / Refund
    public function approve(Request $request, $id)
    {
        // Optional refund payload validation
        $request->validate([
            'refund.amount'      => 'nullable|numeric|min:0.01',
            'refund.method'      => 'nullable|string',
            'refund.reference'   => 'nullable|string',
            'refund.refunded_at' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($request, $id) {
            /** @var \App\Models\SaleReturn $return */
            $return = SaleReturn::query()
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            // 1) Approve (idempotent)
            if ($return->status !== 'approved') {
                $return->update(['status' => 'approved']);
            }

            $refundedNow = $this->storeRefund($return, $request);
            $amount = (float) data_get($request->input('refund'), 'amount', 0);
            return \App\Http\Response\ApiResponse::success([
                'return'         => $return->fresh(),
                'refunded_total' => round((float)$refundedNow, 2),
                'refundable_left' => round(max(0, (float)$return->total - (float)$refundedNow), 2),
            ], 'Sale return approved' . ($amount > 0 ? ' and refund posted' : ''));
        });
    }

    public function refund(Request $request, $id)
    {
        $request->validate([
            'amount'      => 'required|numeric|min:0.01',
            'method'      => 'nullable|string',
            'reference'   => 'nullable|string',
            'refunded_at' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($request, $id) {
            /** @var \App\Models\SaleReturn $return */
            $return = SaleReturn::query()
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            $refundedNow = $this->storeRefund($return, $request);

            return \App\Http\Response\ApiResponse::success([
                'return'          => $return->fresh(),
                'refunded_total'  => round((float)$refundedNow, 2),
                'refundable_left' => round(max(0, (float)$return->total - (float)$refundedNow), 2),
            ], 'Refund posted');
        });
    }

    protected function storeRefund(SaleReturn $return, Request $request)
    {
        if ($return->status !== 'approved') {
            throw ValidationException::withMessages([
                'status' => 'Return must be approved before refunding.',
            ]);
        }

        $amount = (float) $request->input('amount');

        // Already refunded total (approved cash rows)
        $refunded = SaleReturnRefund::query()
            ->where('sale_return_id', $return->id)
            ->sum('amount');

        $left = max(0, (float)$return->total - (float)$refunded);
        if ($amount > $left) {
            throw ValidationException::withMessages([
                'amount' => "Amount {$amount} exceeds refundable left {$left}.",
            ]);
        }

        SaleReturnRefund::create([
            'sale_return_id' => $return->id,
            'amount'         => (float)$request->input('refund.amount'),
            'method'         => $request->input('refund.method', 'cash'),
            'reference'      => $request->input('refund.reference'),
            'refunded_at'    => $request->input('refund.refunded_at'),
            'created_by'     => optional($request->user())->id,
        ]);

        return $refunded + $amount;
    }
}
