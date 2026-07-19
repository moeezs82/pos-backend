<?php

namespace App\Services;

use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Single transactional owner of accounting-safe stock initialization and
 * adjustment, so manual product creation and CSV/XLSX import can never drift
 * apart again.
 *
 * Invariant it protects, per branch+product:
 *   product_stocks.quantity == SUM(stock_movements.quantity)
 *   inventory GL (1400) delta == valuation of those movements
 *
 * Quantities are decimal(14,3); avg_cost decimal(18,4); money rounded to 2.
 *
 * Contra accounts (matching the existing manual StockController::adjust):
 *   - Genuine opening balance (new product):  CR Opening Equity 3100
 *   - Later positive adjustment (gain):       CR 5205 (PPV / gain)
 *   - Later negative adjustment (write-off):  DR 5100 (COGS)
 */
class StockPostingService
{
    private const INVENTORY = '1400';
    private const OPENING_EQUITY = '3100';
    private const GAIN = '5205';   // positive adjustment contra
    private const WRITE_OFF = '5100'; // negative adjustment contra

    public function __construct(private AccountingService $accounting) {}

    private static function qty(float $v): float { return round($v, 3); }
    private static function money(float $v): float { return round($v, 2); }
    private static function avg(float $v): float { return round($v, 4); }

    /**
     * Genuine opening stock for a NEW product: moving-average receive + one
     * `adjustment` movement (ref OPENING) + a balanced DR 1400 / CR 3100 entry
     * when the value is non-zero.
     *
     * @return array{posted:bool, delta:float, value:float, movement_id:?int}
     */
    public function initializeOpeningStock(int $productId, ?int $branchId, float $qty, float $unitCost, array $meta = []): array
    {
        $qty = self::qty($qty);
        if ($qty <= 0) {
            return ['posted' => false, 'delta' => 0.0, 'value' => 0.0, 'movement_id' => null];
        }

        return DB::transaction(function () use ($productId, $branchId, $qty, $unitCost, $meta) {
            $row = DB::table('product_stocks')->lockForUpdate()
                ->where('product_id', $productId)->where('branch_id', $branchId)->first();

            $oldQty  = (float) ($row->quantity ?? 0);
            $oldCost = (float) ($row->avg_cost ?? 0);
            $newQty  = self::qty($oldQty + $qty);
            $newAvg  = $newQty > 0
                ? self::avg((($oldQty * $oldCost) + ($qty * $unitCost)) / $newQty)
                : self::avg($unitCost);

            DB::table('product_stocks')->updateOrInsert(
                ['product_id' => $productId, 'branch_id' => $branchId],
                ['quantity' => $newQty, 'avg_cost' => $newAvg, 'updated_at' => now(), 'created_at' => $row->created_at ?? now()]
            );

            $movement = StockMovement::create([
                'product_id' => $productId,
                'branch_id'  => $branchId,
                'type'       => 'adjustment',
                'quantity'   => $qty,
                'reference'  => $meta['reference'] ?? 'OPENING',
            ]);

            $value = self::money($qty * $unitCost);
            if ($value > 0) {
                $this->accounting->post(
                    branchId: $branchId,
                    memo: $meta['memo'] ?? 'Opening stock',
                    reference: $movement,
                    lines: [
                        ['account_code' => self::INVENTORY, 'debit' => $value, 'credit' => 0],
                        ['account_code' => self::OPENING_EQUITY, 'debit' => 0, 'credit' => $value],
                    ],
                    entryDate: $meta['date'] ?? now()->toDateString(),
                    userId: $meta['user_id'] ?? auth()->id(),
                );
            }

            return ['posted' => $value > 0, 'delta' => $qty, 'value' => $value, 'movement_id' => $movement->id];
        });
    }

    /**
     * Move an EXISTING product's branch stock to an absolute target quantity by
     * posting ONLY the delta. Re-importing an unchanged target is a no-op.
     *
     * Increase → valued at $increaseUnitCost (else current avg), moving average
     * recalculated, DR 1400 / CR 5205. Decrease → valued at current avg,
     * DR 5100 / CR 1400 (avg unchanged).
     *
     * @return array{direction:string, delta:float, value:float, movement_id:?int}
     */
    public function adjustToTarget(int $productId, ?int $branchId, float $targetQty, ?float $increaseUnitCost, array $meta = []): array
    {
        $targetQty = self::qty($targetQty);

        return DB::transaction(function () use ($productId, $branchId, $targetQty, $increaseUnitCost, $meta) {
            $row = DB::table('product_stocks')->lockForUpdate()
                ->where('product_id', $productId)->where('branch_id', $branchId)->first();

            if (!$row) {
                DB::table('product_stocks')->insert([
                    'product_id' => $productId, 'branch_id' => $branchId,
                    'quantity' => 0, 'avg_cost' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $row = (object) ['quantity' => 0, 'avg_cost' => 0];
            }

            $current = (float) ($row->quantity ?? 0);
            $avg     = (float) ($row->avg_cost ?? 0);
            $delta   = self::qty($targetQty - $current);

            if (abs($delta) < 1e-9) {
                return ['direction' => 'none', 'delta' => 0.0, 'value' => 0.0, 'movement_id' => null];
            }

            if ($delta > 0) {
                $unitCost = ($increaseUnitCost !== null && $increaseUnitCost > 0) ? $increaseUnitCost : $avg;
                $newQty   = self::qty($current + $delta);
                $newAvg   = $newQty > 0
                    ? self::avg((($current * $avg) + ($delta * $unitCost)) / $newQty)
                    : self::avg($unitCost);

                DB::table('product_stocks')
                    ->where('product_id', $productId)->where('branch_id', $branchId)
                    ->update(['quantity' => $newQty, 'avg_cost' => $newAvg, 'updated_at' => now()]);

                $movement = StockMovement::create([
                    'product_id' => $productId, 'branch_id' => $branchId,
                    'type' => 'adjustment', 'quantity' => $delta,
                    'reference' => $meta['reference'] ?? 'IMPORT-ADJUST',
                ]);

                $value = self::money($delta * $unitCost);
                if ($value > 0) {
                    $this->accounting->post(
                        branchId: $branchId,
                        memo: $meta['memo'] ?? 'Stock adjustment (increase)',
                        reference: $movement,
                        lines: [
                            ['account_code' => self::INVENTORY, 'debit' => $value, 'credit' => 0],
                            ['account_code' => self::GAIN, 'debit' => 0, 'credit' => $value],
                        ],
                        entryDate: $meta['date'] ?? now()->toDateString(),
                        userId: $meta['user_id'] ?? auth()->id(),
                    );
                }

                return ['direction' => 'increase', 'delta' => $delta, 'value' => $value, 'movement_id' => $movement->id];
            }

            // delta < 0 — reduce at current average cost, avg unchanged.
            $qtyOut = self::qty(-$delta);
            $newQty = self::qty($current + $delta);
            DB::table('product_stocks')
                ->where('product_id', $productId)->where('branch_id', $branchId)
                ->update(['quantity' => $newQty, 'updated_at' => now()]);

            $movement = StockMovement::create([
                'product_id' => $productId, 'branch_id' => $branchId,
                'type' => 'adjustment', 'quantity' => $delta, // negative
                'reference' => $meta['reference'] ?? 'IMPORT-ADJUST',
            ]);

            $value = self::money($qtyOut * $avg);
            if ($value > 0) {
                $this->accounting->post(
                    branchId: $branchId,
                    memo: $meta['memo'] ?? 'Stock adjustment (decrease)',
                    reference: $movement,
                    lines: [
                        ['account_code' => self::WRITE_OFF, 'debit' => $value, 'credit' => 0],
                        ['account_code' => self::INVENTORY, 'debit' => 0, 'credit' => $value],
                    ],
                    entryDate: $meta['date'] ?? now()->toDateString(),
                    userId: $meta['user_id'] ?? auth()->id(),
                );
            }

            return ['direction' => 'decrease', 'delta' => $delta, 'value' => $value, 'movement_id' => $movement->id];
        });
    }
}
