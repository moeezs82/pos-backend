<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Services\AccountingService;
use App\Services\BranchContextService;
use App\Services\ProductBranchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    // View stock per branch
    public function index(Request $request, BranchContextService $branches)
    {
        $query = ProductStock::with(['product', 'branch']);
        $branches->applyToQuery($query, $request, 'branch_id');

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $stocks = $query->paginate($request->get('per_page', 20));
        return ApiResponse::success($stocks, 'Stocks retrived successfully');
    }

    // Offline-sync guardrail report (handover doc §1.5): products currently
    // at negative on-hand quantity, alongside the sales flagged with
    // meta.stock_conflict = true that pushed them there (from two offline
    // devices selling the same last unit before either synced). Managers
    // use this to do a manual stock adjustment; nothing here blocks or
    // reverses the sale.
    public function negativeStockConflicts(Request $request, BranchContextService $branches)
    {
        $stockQuery = ProductStock::with(['product', 'branch'])
            ->where('quantity', '<', 0);
        $branches->applyToQuery($stockQuery, $request, 'branch_id');
        $negativeStocks = $stockQuery->get();

        // ->where('meta->stock_conflict', true) (rather than
        // whereJsonContains, which is for JSON arrays) — meta.stock_conflict
        // is a scalar boolean, so this compiles to a JSON path equality
        // check.
        $salesQuery = \App\Models\Sale::with(['items', 'customer:id,first_name,last_name'])
            ->where('meta->stock_conflict', true)
            ->orderByDesc('created_at');
        $branches->applyToQuery($salesQuery, $request, 'branch_id');
        $flaggedSales = $salesQuery->get();

        return ApiResponse::success([
            'negative_stocks' => $negativeStocks,
            'flagged_sales'   => $flaggedSales,
        ], 'Offline sync stock conflicts');
    }

    // Adjust stock (increase/decrease manually)
    public function adjust(Request $request, AccountingService $accounting, BranchContextService $branches, ProductBranchService $productBranches)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'branch_id'  => 'nullable|exists:branches,id',
            'quantity'   => 'required|numeric', // +10 = add, -5 = reduce (decimals allowed)
            'reason'     => 'nullable|string'
        ]);

        $branchId  = $branches->requireBranchId($request);
        $productBranches->assertProductsBelongToBranch([(int) $data['product_id']], $branchId);

        $stock = ProductStock::firstOrCreate(
                ['product_id' => $data['product_id'], 'branch_id' => $branchId],
                ['quantity' => 0]
            );
        
        $qty       = (float) $data['quantity'];
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
    public function transfer(Request $request, BranchContextService $branches, ProductBranchService $productBranches)
    {
        $data = $request->validate([
            'product_id'   => 'required|exists:products,id',
            'from_branch'  => 'required|exists:branches,id',
            'to_branch'    => 'required|exists:branches,id|different:from_branch',
            'quantity'     => 'required|numeric|min:0.001',
            'reference'    => 'nullable|string', // e.g., transfer voucher number
        ]);

        $activeBranchId = $branches->requireBranchId($request);
        if ((int) $data['from_branch'] !== $activeBranchId) {
            return ApiResponse::error('Please switch to the source branch before transferring stock.', 422);
        }
        $productBranches->assertProductsBelongToBranch([(int) $data['product_id']], $activeBranchId);

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
