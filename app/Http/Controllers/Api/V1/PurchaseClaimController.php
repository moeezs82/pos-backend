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
            'purchase_id' => 'required|exists:purchases,id',
            'type'        => 'nullable|in:shortage,damaged,wrong_item,expired,other',
            'reason'      => 'nullable|string',
            'items'       => 'required|array|min:1',
            'items.*.purchase_item_id' => 'required|exists:purchase_items,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.affects_stock'    => 'nullable|boolean', // default inferred by type (see below)
            'items.*.remarks'          => 'nullable|string',
            'items.*.batch_no'         => 'nullable|string',
            'items.*.expiry_date'      => 'nullable|date',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $purchase = Purchase::with(['items'])->findOrFail($data['purchase_id']);

            // Map purchase items for quick validation
            $purchaseItems = $purchase->items->keyBy('id');

            $claimNo = 'PCL-' . time();

            $claim = PurchaseClaim::create([
                'claim_no'   => $claimNo,
                'purchase_id' => $purchase->id,
                'vendor_id'  => $purchase->vendor_id,
                'branch_id'  => $purchase->branch_id,
                'type'       => $data['type'] ?? 'other',
                'reason'     => $data['reason'] ?? null,
                'status'     => 'pending',
                'subtotal'   => 0,
                'tax'        => 0,
                'total'      => 0,
                'created_by' => optional($request->user())->id,
            ]);

            $subtotal = 0;

            foreach ($data['items'] as $i) {
                /** @var PurchaseItem|null $pItem */
                $pItem = $purchaseItems->get($i['purchase_item_id']);
                if (!$pItem) {
                    return ApiResponse::error("Purchase item #{$i['purchase_item_id']} does not belong to purchase #{$purchase->id}.", 422);
                }

                $qty = (int) $i['quantity'];
                if ($qty < 1) $qty = 1;

                // Price: use purchase item price (cost)
                $price = (float) $pItem->price;
                $line  = $qty * $price;
                $subtotal += $line;

                // Affects-stock default rule: shortage = false; otherwise true
                $affects = array_key_exists('affects_stock', $i)
                    ? (bool)$i['affects_stock']
                    : (($data['type'] ?? 'other') !== 'shortage');

                $claim->items()->create([
                    'purchase_item_id' => $pItem->id,
                    'product_id'       => $pItem->product_id,
                    'quantity'         => $qty,
                    'price'            => $price,
                    'total'            => $line,
                    'affects_stock'    => $affects,
                    'remarks'          => $i['remarks'] ?? null,
                    'batch_no'         => $i['batch_no'] ?? null,
                    'expiry_date'      => $i['expiry_date'] ?? null,
                ]);
            }

            $claim->update([
                'subtotal' => $subtotal,
                'total'    => $subtotal, // add tax calculation here if needed
            ]);

            return ApiResponse::success($claim->load(['items.product:id,name,sku']));
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
