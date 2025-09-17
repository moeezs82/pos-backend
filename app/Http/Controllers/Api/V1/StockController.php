<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\ProductStock;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    // View stock per branch
    public function index(Request $request)
    {
        $query = ProductStock::with(['product', 'branch']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $stocks = $query->paginate($request->get('per_page', 20));
        return ApiResponse::success($stocks, 'Stocks retrived successfully');
    }

    // Adjust stock (increase/decrease manually)
    public function adjust(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'branch_id'  => 'required|exists:branches,id',
            'quantity'   => 'required|integer', // +10 = add, -5 = reduce
            'reason'     => 'nullable|string'
        ]);

        $stock = ProductStock::firstOrCreate(
            ['product_id' => $data['product_id'], 'branch_id' => $data['branch_id']],
            ['quantity' => 0]
        );

        $stock->quantity += $data['quantity'];
        $stock->save();

        StockMovement::create([
            'product_id' => $data['product_id'],
            'branch_id'  => $data['branch_id'],
            'type'       => 'adjustment',
            'quantity'   => $data['quantity'],
            'reference'  => $data['reason'] ?? 'manual-adjustment'
        ]);

        return ApiResponse::success($stock, 'Stock adjusted successfully');
    }

    // Transfer stock between branches
    public function transfer(Request $request)
    {
        $data = $request->validate([
            'product_id'   => 'required|exists:products,id',
            'from_branch'  => 'required|exists:branches,id',
            'to_branch'    => 'required|exists:branches,id|different:from_branch',
            'quantity'     => 'required|integer|min:1',
            'reference'    => 'nullable|string', // e.g., transfer voucher number
        ]);

        DB::beginTransaction();

        try {
            // 🔹 Lock source stock row to avoid race conditions
            $from = ProductStock::where('product_id', $data['product_id'])
                ->where('branch_id', $data['from_branch'])
                ->lockForUpdate()
                ->first();

            if (!$from || $from->quantity < $data['quantity']) {
                DB::rollBack();
                return response()->json(['message' => 'Not enough stock to transfer'], 422);
            }

            // Decrease from source
            $from->decrement('quantity', $data['quantity']);

            StockMovement::create([
                'product_id' => $data['product_id'],
                'branch_id'  => $data['from_branch'],
                'type'       => 'transfer_out',
                'quantity'   => -$data['quantity'],
                'reference'  => $data['reference'] ?? 'transfer-to-' . $data['to_branch'],
            ]);

            // Increase in destination
            $to = ProductStock::firstOrCreate(
                ['product_id' => $data['product_id'], 'branch_id' => $data['to_branch']],
                ['quantity' => 0]
            );
            $to->increment('quantity', $data['quantity']);

            StockMovement::create([
                'product_id' => $data['product_id'],
                'branch_id'  => $data['to_branch'],
                'type'       => 'transfer_in',
                'quantity'   => $data['quantity'],
                'reference'  => $data['reference'] ?? 'transfer-from-' . $data['from_branch'],
            ]);

            DB::commit();

            return ApiResponse::success([
                'from_branch' => $from->branch->name ?? null,
                'to_branch'   => $to->branch->name ?? null,
                'product_id'  => $data['product_id'],
                'quantity'    => $data['quantity'],
            ], 'Stock transferred successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Transfer failed', 'error' => $e->getMessage()], 500);
        }
    }
}
