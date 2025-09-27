<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Purchase;
use App\Models\PurchaseClaim;
use App\Models\StockMovement;
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
        ]);

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
                    ->orWhereHas('purchase', fn($p) => $p->where('invoice_no', 'like', "%$search%"))
                    ->orWhereHas('purchase.vendor', fn($v) => $v->where('name', 'like', "%$search%"));
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
            'items.product:id,name,sku'
        ])->findOrFail($id);

        return ApiResponse::success($claim);
    }

    public function store(Request $request)
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
        ]);

        return DB::transaction(function () use ($data, $request) {
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

            // If any lines affect stock, decrement product_stocks in ONE SQL
            if (!empty($byProductDelta)) {
                $productIds = array_keys($byProductDelta);

                // Lock rows to prevent races
                DB::table('product_stocks')
                    ->where('branch_id', $purchase->branch_id)
                    ->whereIn('product_id', $productIds)
                    ->lockForUpdate()
                    ->get();

                $case = collect($byProductDelta)->map(function ($qty, $pid) {
                    return "WHEN {$pid} THEN {$qty}";
                })->implode(' ');

                DB::update("
                UPDATE product_stocks
                SET quantity = quantity - CASE product_id {$case} END
                WHERE branch_id = ? AND product_id IN (" . implode(',', $productIds) . ")
            ", [$purchase->branch_id]);

                // Stock movements — bulk insert (ONE query)
                $movementRows = [];
                foreach ($byProductDelta as $pid => $qty) {
                    $movementRows[] = [
                        'product_id' => $pid,
                        'branch_id'  => $purchase->branch_id,
                        'type'       => 'purchase-claim', // or 'adjustment'
                        'quantity'   => -$qty,            // negative because stock reduced
                        'reference'  => $claim->claim_no,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                DB::table('stock_movements')->insert($movementRows);
            }

            return \App\Http\Response\ApiResponse::success(
                $claim->load(['items.product:id,name,sku'])
            );
        });
    }

    public function approve($id, Request $request)
    {
        return DB::transaction(function () use ($id, $request) {
            // Lock the claim to avoid double-approval races
            $claim = PurchaseClaim::with(['items'])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($claim->status !== 'pending') {
                return ApiResponse::error("Only pending claims can be approved.", 422);
            }

            foreach ($claim->items as $item) {
                // 🚦 Still honor affects_stock (e.g., shortage shouldn’t deduct)
                if (!$item->affects_stock) {
                    continue;
                }

                // Ensure a stock row exists; if not, create with 0 to allow negative
                $exists = DB::table('product_stocks')
                    ->where('product_id', $item->product_id)
                    ->where('branch_id',  $claim->branch_id)
                    ->exists();

                if (!$exists) {
                    DB::table('product_stocks')->insert([
                        'product_id' => $item->product_id,
                        'branch_id'  => $claim->branch_id,
                        'quantity'   => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Lock the row *after* ensuring it exists
                $stockRow = DB::table('product_stocks')
                    ->where('product_id', $item->product_id)
                    ->where('branch_id',  $claim->branch_id)
                    ->lockForUpdate()
                    ->first();

                // Allow negative: just decrement (no available-qty check)
                DB::table('product_stocks')
                    ->where('product_id', $item->product_id)
                    ->where('branch_id',  $claim->branch_id)
                    ->decrement('quantity', (int)$item->quantity);

                // Movement log (negative outflow)
                StockMovement::create([
                    'product_id' => $item->product_id,
                    'branch_id'  => $claim->branch_id,
                    'type'       => 'adjustment',     // or 'return' if you prefer
                    'quantity'   => -1 * (int)$item->quantity,
                    'reference'  => $claim->claim_no,
                ]);
            }

            $claim->update([
                'status'      => 'approved',
                'approved_by' => optional($request->user())->id,
                'approved_at' => now(),
            ]);

            return ApiResponse::success($claim->fresh(['items.product:id,name,sku']));
        });
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
