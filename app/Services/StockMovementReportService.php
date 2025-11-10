<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use DateTimeImmutable;
use DateInterval;

class StockMovementReportService
{
    /**
     * Detailed stock movement report with optional valuation.
     *
     * Params ($p):
     * - from?: 'Y-m-d' | Carbon
     * - to?:   'Y-m-d' | Carbon
     * - product_id?: int | int[]
     * - branch_id?:  int | int[]
     * - type?:       string | string[]       // e.g. purchase, sale, return, transfer, adjustment
     * - include_value?: bool                 // default false; pulls valuation via Inventory (1400)
     * - inventory_account_code?: string      // default '1400'
     * - page?: int                           // default 1
     * - per_page?: int (1..100)              // default 20
     * - order?: 'asc'|'desc'                 // default 'asc' by (eff_date, id)
     *
     * Returns:
     * - opening: { quantity, value, avg_cost }
     * - rows[]: [
     *      id, date, product_id, product_name, sku, branch_id, branch_name, type, reference,
     *      qty_in, qty_out, balance_qty,
     *      (amount_in, amount_out, balance_value, avg_cost?) // when include_value
     *   ]
     * - totals: { qty_in, qty_out, net_qty, (amount_in, amount_out, net_value) }
     * - paging: { current_page, per_page, total, last_page }
     * - filters: echo of applied filters
     */
    public function movementDetail(array $p): array
    {
        // ---------- Normalize inputs
        $today = new DateTimeImmutable(date('Y-m-d'));
        $from = isset($p['from']) && $p['from']
            ? new DateTimeImmutable(is_string($p['from']) ? $p['from'] : $p['from']->format('Y-m-d'))
            : $today->sub(new DateInterval('P30D'));
        $to = isset($p['to']) && $p['to']
            ? new DateTimeImmutable(is_string($p['to']) ? $p['to'] : $p['to']->format('Y-m-d'))
            : $today;

        if ($from > $to) {
            throw new InvalidArgumentException('`from` must be on or before `to`.');
        }

        $includeValue = (bool)($p['include_value'] ?? false);
        $invCode      = $p['inventory_account_code'] ?? '1400';

        $page    = max(1, (int)($p['page'] ?? 1));
        $perPage = max(1, min(100, (int)($p['per_page'] ?? 20)));
        $order   = strtolower($p['order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        // Accept scalars or arrays for filters
        $productIds = $this->toIntArray($p['product_id'] ?? null);
        $branchIds  = $this->toIntArray($p['branch_id'] ?? null);
        $types      = $this->toStrArray($p['type'] ?? null);

        $effDateExpr = "COALESCE(sm.created_at)";

        // ---------- Resolve inventory account id (for valuation)
        $inventoryAccountId = null;
        if ($includeValue) {
            $inventoryAccountId = DB::table('accounts')->where('code', $invCode)->value('id');
            if (!$inventoryAccountId) {
                throw new InvalidArgumentException("Inventory account code {$invCode} not found.");
            }
        }

        // ---------- Opening quantity before from
        $openingQtyQ = DB::table('stock_movements as sm')
            ->whereRaw("$effDateExpr < ?", [$from->format('Y-m-d 00:00:00')]);

        if ($productIds) $openingQtyQ->whereIn('sm.product_id', $productIds);
        if ($branchIds)  $openingQtyQ->whereIn('sm.branch_id', $branchIds);
        if ($types)      $openingQtyQ->whereIn('sm.type', $types);

        $openingQty = (float) $openingQtyQ->selectRaw('COALESCE(SUM(sm.quantity),0) as q')->value('q');

        // ---------- Opening value (optional) from GL before from (Inventory postings tied to movements)
        $openingValue = 0.0;
        if ($includeValue) {
            $refType = addslashes(\App\Models\StockMovement::class);

            $openValQ = DB::table('journal_entries as je')
                ->join('journal_postings as jp', 'jp.journal_entry_id', '=', 'je.id')
                ->join('stock_movements as sm', function ($j) use ($refType) {
                    $j->on('sm.id', '=', 'je.reference_id')
                      ->where('je.reference_type', '=', $refType);
                })
                ->where('jp.account_id', $inventoryAccountId)
                ->whereRaw("COALESCE(jp.created_at, je.entry_date, je.created_at) < ?", [$from->format('Y-m-d 00:00:00')]);

            if ($productIds) $openValQ->whereIn('sm.product_id', $productIds);
            if ($branchIds)  $openValQ->whereIn('sm.branch_id', $branchIds);
            if ($types)      $openValQ->whereIn('sm.type', $types);

            // For asset Inventory, debit increases value, credit decreases
            $openingValue = (float) $openValQ
                ->selectRaw('COALESCE(SUM(jp.debit - jp.credit),0) as val')
                ->value('val');
        }

        // ---------- Base movements in range
        $baseQ = DB::table('stock_movements as sm')
            ->leftJoin('products as p', 'p.id', '=', 'sm.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'sm.branch_id')
            ->whereRaw("$effDateExpr >= ?", [$from->format('Y-m-d 00:00:00')])
            ->whereRaw("$effDateExpr <= ?", [$to->format('Y-m-d 23:59:59')]);

        if ($productIds) $baseQ->whereIn('sm.product_id', $productIds);
        if ($branchIds)  $baseQ->whereIn('sm.branch_id', $branchIds);
        if ($types)      $baseQ->whereIn('sm.type', $types);

        $countQ = (clone $baseQ);
        $total  = (int) $countQ->count();

        $rowsQ = (clone $baseQ)
            ->selectRaw("
                sm.id,
                $effDateExpr as eff_date,
                sm.product_id,
                p.name as product_name,
                p.sku as sku,
                sm.branch_id,
                b.name as branch_name,
                sm.type,
                sm.reference,
                sm.quantity
            ")
            ->orderByRaw("$effDateExpr $order")
            ->orderBy('sm.id', $order)
            ->skip(($page - 1) * $perPage)
            ->take($perPage);

        $rows = $rowsQ->get();

        // ---------- Fetch valuation for the page (one shot) if asked
        $amountByMovement = [];
        if ($includeValue && $rows->isNotEmpty()) {
            $refType = addslashes(\App\Models\StockMovement::class);
            $ids = $rows->pluck('id')->all();

            $amtQ = DB::table('journal_entries as je')
                ->join('journal_postings as jp', 'jp.journal_entry_id', '=', 'je.id')
                ->where('jp.account_id', $inventoryAccountId)
                ->where('je.reference_type', $refType)
                ->whereIn('je.reference_id', $ids)
                ->selectRaw('je.reference_id as movement_id, COALESCE(SUM(jp.debit - jp.credit),0) as amt')
                ->groupBy('movement_id')
                ->get();

            foreach ($amtQ as $r) {
                $amountByMovement[(int)$r->movement_id] = (float)$r->amt;
            }
        }

        // ---------- Running balances
        $runningQty   = $openingQty;
        $runningValue = $openingValue;

        $out = [];
        $totQtyIn = $totQtyOut = 0.0;
        $totAmtIn = $totAmtOut = 0.0;

        foreach ($rows as $r) {
            $qty = (float)$r->quantity;
            $qtyIn  = $qty > 0 ? $qty : 0.0;
            $qtyOut = $qty < 0 ? abs($qty) : 0.0;

            // Update running qty
            $runningQty += $qty;

            // Amounts if available
            $amount = $includeValue ? (float)($amountByMovement[$r->id] ?? 0.0) : 0.0;
            $amtIn  = $amount > 0 ? $amount : 0.0;      // Inventory increases => debit
            $amtOut = $amount < 0 ? abs($amount) : 0.0; // Inventory decreases => credit

            if ($includeValue) {
                $runningValue += $amount;
            }

            $row = [
                'id'           => (int)$r->id,
                'date'         => (string)$r->eff_date,
                'product_id'   => (int)$r->product_id,
                'product_name' => $r->product_name,
                'sku'          => $r->sku,
                'branch_id'    => (int)$r->branch_id,
                'branch_name'  => $r->branch_name,
                'type'         => $r->type,
                'reference'    => $r->reference,
                'qty'       => $qty,
                'qty_in'       => round($qtyIn, 3),
                'qty_out'      => round($qtyOut, 3),
                'balance_qty'  => round($runningQty, 3),
            ];

            if ($includeValue) {
                $row['amount_in']    = round($amtIn, 2);
                $row['amount_out']   = round($amtOut, 2);
                $row['balance_value']= round($runningValue, 2);
                $row['avg_cost']     = $runningQty > 0 ? round($runningValue / $runningQty, 6) : null;
            }

            $out[] = $row;

            $totQtyIn  += $qtyIn;
            $totQtyOut += $qtyOut;
            if ($includeValue) {
                $totAmtIn  += $amtIn;
                $totAmtOut += $amtOut;
            }
        }

        $lastPage = (int)ceil($total / $perPage);

        $result = [
            'opening' => [
                'quantity' => round($openingQty, 3),
                'value'    => $includeValue ? round($openingValue, 2) : null,
                'avg_cost' => ($includeValue && $openingQty > 0) ? round($openingValue / $openingQty, 6) : null,
            ],
            'rows' => $out,
            'totals' => array_filter([
                'qty_in'    => round($totQtyIn, 3),
                'qty_out'   => round($totQtyOut, 3),
                'net_qty'   => round($totQtyIn - $totQtyOut, 3),
                'amount_in' => $includeValue ? round($totAmtIn, 2) : null,
                'amount_out'=> $includeValue ? round($totAmtOut, 2) : null,
                'net_value' => $includeValue ? round($totAmtIn - $totAmtOut, 2) : null,
            ], fn($v) => $v !== null),
            'paging' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => $lastPage,
            ],
            'filters' => [
                'from'       => $from->format('Y-m-d'),
                'to'         => $to->format('Y-m-d'),
                'product_id' => $productIds,
                'branch_id'  => $branchIds,
                'type'       => $types,
                'include_value' => $includeValue,
                'inventory_account_code' => $invCode,
                'order'      => $order,
            ],
        ];

        return $result;
    }

    // ----- helpers
    private function toIntArray($v): array
    {
        if ($v === null || $v === '') return [];
        if (is_array($v))  return array_values(array_filter(array_map('intval', $v)));
        return [ (int)$v ];
    }

    private function toStrArray($v): array
    {
        if ($v === null || $v === '') return [];
        if (is_array($v))  return array_values(array_filter(array_map('strval', $v)));
        return [ (string)$v ];
    }
}
