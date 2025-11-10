<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Services\AccountingService;
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
    public function adjust(Request $request, AccountingService $accounting)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'branch_id'  => 'nullable|exists:branches,id',
            'quantity'   => 'required|integer', // +10 = add, -5 = reduce
            'reason'     => 'nullable|string'
        ]);

        $branchId  = $data['branch_id'] ?? null;

        $stock = ProductStock::firstOrCreate(
                ['product_id' => $data['product_id'], 'branch_id' => $branchId],
                ['quantity' => 0]
            );
        
        $qty       = (int) $data['quantity'];
        $unitCost  = (float) $stock->avg_cost;
        $amount    = round(abs($qty) * $unitCost, 2); // total valuation
        $memo      = trim('Inventory adjustment: ' . ($data['reason'] ?? 'manual-adjustment'));
        $entryDate = $data['entry_date'] ?? now()->toDateString();
        $userId    = optional($request->user())->id;

        if ($amount <= 0) {
            return ApiResponse::error('Unit cost must be > 0 to post accounting.', 422);
        }

        // Account codes (from your seeder)
        $accounts = [
            'inventory' => '1400', // Inventory
            'cogs'      => '5100', // COGS -> for negative adjustments (write-off)
            'ppv'       => '5205', // Purchase Price Variance -> used as "gain" contra-expense for positive adjustments
        ];

        $result = DB::transaction(function () use (
            $data, $qty, $unitCost, $amount, $branchId, $memo, $entryDate, $userId, $accounts, $accounting, $stock
        ) {
            // 1) Adjust physical stock
            
            $stock->quantity += $qty;
            $stock->save();

            // 2) Record movement (store valuation too if columns exist; otherwise ignore gracefully)
            $movementAttrs = [
                'product_id' => $data['product_id'],
                'branch_id'  => $branchId,
                'type'       => 'adjustment',
                'quantity'   => $qty,
                'reference'  => $data['reason'] ?? 'manual-adjustment',
                // Optional fields—uncomment if your table has them:
                // 'unit_cost'  => $unitCost,
                // 'amount'     => $amount,
                // 'moved_at'   => $entryDate,
            ];
            /** @var \App\Models\StockMovement $movement */
            $movement = StockMovement::create($movementAttrs);

            // 3) Post accounting (double-entry)
            // Positive qty => increase inventory: DR Inventory, CR PPV (acting as gain/contra-expense)
            // Negative qty => write-off:        DR COGS,     CR Inventory
            if ($qty > 0) {
                $lines = [
                    ['account_code' => $accounts['inventory'], 'debit' => $amount, 'credit' => 0],
                    ['account_code' => $accounts['ppv'],       'debit' => 0,       'credit' => $amount],
                ];
            } else {
                $lines = [
                    ['account_code' => $accounts['cogs'],      'debit' => $amount, 'credit' => 0],
                    ['account_code' => $accounts['inventory'], 'debit' => 0,       'credit' => $amount],
                ];
            }

            $je = $accounting->post(
                $branchId,
                $memo,
                $movement,   // will be saved as reference_type/id in journal_entries
                $lines,
                $entryDate,
                $userId
            );

            return compact('stock', 'movement', 'je');
        });

        return ApiResponse::success($result, 'Stock adjusted and journal posted successfully');
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
