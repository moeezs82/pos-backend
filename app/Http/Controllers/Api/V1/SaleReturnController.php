<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleReturnController extends Controller
{
    public function index(Request $request)
    {
        $query = SaleReturn::with(['sale:id,invoice_no,customer_id,branch_id', 'sale.customer:id,first_name,last_name', 'sale.branch:id,name']);

        if ($request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        return ApiResponse::success($query->paginate(15));
    }

    public function show($id)
    {
        $return = SaleReturn::with([
            'sale:id,invoice_no,customer_id,branch_id,subtotal,total',
            'sale.customer:id,first_name,last_name,email,phone',
            'sale.branch:id,name',
            'items.product:id,name,sku'
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
        ]);

        $sale = Sale::where('id', $data['sale_id'])->lockForUpdate()->firstOrFail();
        return DB::transaction(function () use ($data, $sale) {
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

            return ApiResponse::success($return->load('items'));
        });
    }


    // Approve Return
    public function approve($id)
    {
        $return = SaleReturn::findOrFail($id);
        $return->update(['status' => 'approved']);
        return ApiResponse::success($return);
    }
}
