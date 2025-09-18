<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Sale;
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
            'sale_id' => 'required|exists:sales,id',
            'items'   => 'required|array|min:1',
            'items.*.sale_item_id' => 'required|exists:sale_items,id',
            'items.*.quantity'     => 'required|integer|min:1',
            'reason' => 'nullable|string'
        ]);

        return DB::transaction(function () use ($data) {
            $sale = Sale::with('items')->findOrFail($data['sale_id']);

            $subtotal = 0;
            $return = SaleReturn::create([
                'sale_id'     => $sale->id,
                'customer_id' => $sale->customer_id,
                'branch_id'   => $sale->branch_id,
                'return_no'   => 'RET-' . time(),
                'subtotal'    => 0,
                'tax'         => 0,
                'total'       => 0,
                'reason'      => $data['reason'] ?? null,
            ]);

            foreach ($data['items'] as $rItem) {
                $saleItem = $sale->items->find($rItem['sale_item_id']);
                $qty = min($rItem['quantity'], $saleItem->quantity);

                $lineTotal = $qty * $saleItem->price;
                $subtotal += $lineTotal;

                $return->items()->create([
                    'sale_item_id' => $saleItem->id,
                    'product_id'   => $saleItem->product_id,
                    'quantity'     => $qty,
                    'price'        => $saleItem->price,
                    'total'        => $lineTotal,
                ]);

                // 1. Restore stock in product_stocks
                DB::table('product_stocks')
                    ->where('product_id', $saleItem->product_id)
                    ->where('branch_id', $sale->branch_id)
                    ->increment('quantity', $qty);

                // 2. Log stock movement
                StockMovement::create([
                    'product_id' => $saleItem->product_id,
                    'branch_id'  => $sale->branch_id,
                    'type'       => 'return',
                    'quantity'   => $qty,
                    'reference'  => $return->return_no,
                ]);
            }

            $return->update([
                'subtotal' => $subtotal,
                'total'    => $subtotal, // add tax if needed
            ]);

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
