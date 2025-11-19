<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReturnAnalyticsService
{
    /**
     * Return Analytics
     *
     * Filters:
     *  - from, to (by sr.created_at)
     *  - branch_id (via sales.branch_id)
     *  - salesman_id (via sales.salesman_id)
     *  - customer_id (via sales.customer_id)
     *
     * Returns array:
     *  - per_day: {
     *        rows: [
     *          { date, return_count, return_qty, return_amount, avg_return_amount,
     *            sales_amount, return_ratio_percent }
     *        ],
     *        totals: { return_count, return_qty, return_amount, sales_amount, return_ratio_percent },
     *        pagination: { current_page, per_page, last_page, total_days }
     *    }
     *  - by_product: [
     *        { product_id, product_name, return_count, return_qty, return_amount }
     *    ]
     *  - by_reason: [
     *        { reason, return_count, return_amount }
     *    ]
     *  - filters: { from, to, branch_id, salesman_id, customer_id }
     */
    public function analytics(
        ?Carbon $from,
        ?Carbon $to,
        ?int $branchId,
        ?int $salesmanId,
        ?int $customerId,
        int $page = 1,
        int $perPage = 30
    ): array {
        $page    = max(1, (int)$page);
        $perPage = max(1, min(30, (int)$perPage));

        // Normalise dates (if given)
        if ($from && $to && $from->gt($to)) {
            throw new InvalidArgumentException('`from` must be on or before `to`.');
        }

        // -------------------------------
        // Base query for sale_returns
        // -------------------------------
        $baseReturns = DB::table('sale_returns as sr')
            ->join('sales as s', 's.id', '=', 'sr.sale_id')
            ->when($from,       fn($q) => $q->where('sr.created_at', '>=', $from->copy()->startOfDay()))
            ->when($to,         fn($q) => $q->where('sr.created_at', '<=', $to->copy()->endOfDay()))
            ->when($branchId,   fn($q) => $q->where('s.branch_id', $branchId))
            ->when($salesmanId, fn($q) => $q->where('s.salesman_id', $salesmanId))
            ->when($customerId, fn($q) => $q->where('s.customer_id', $customerId));

        // -------------------------------
        // Per-day: count + amount
        // -------------------------------
        // Expect sr.total to hold the return total (selling side).
        $returnsPerDay = (clone $baseReturns)
            ->selectRaw("
                DATE(sr.created_at) as d,
                COUNT(sr.id)               as return_count,
                COALESCE(SUM(sr.total), 0) as return_amount
            ")
            ->groupBy(DB::raw('DATE(sr.created_at)'))
            ->orderBy('d')
            ->get()
            ->keyBy('d');

        // -------------------------------
        // Per-day: quantity (from sale_return_items)
        // -------------------------------
        // Assumes sale_return_items has: sale_return_id, quantity, total, product_id (adjust if needed).
        $itemBase = DB::table('sale_return_items as sri')
            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
            ->join('sales as s', 's.id', '=', 'sr.sale_id')
            ->when($from,       fn($q) => $q->where('sr.created_at', '>=', $from->copy()->startOfDay()))
            ->when($to,         fn($q) => $q->where('sr.created_at', '<=', $to->copy()->endOfDay()))
            ->when($branchId,   fn($q) => $q->where('s.branch_id', $branchId))
            ->when($salesmanId, fn($q) => $q->where('s.salesman_id', $salesmanId))
            ->when($customerId, fn($q) => $q->where('s.customer_id', $customerId));

        $qtyPerDay = (clone $itemBase)
            ->selectRaw("
                DATE(sr.created_at) as d,
                COALESCE(SUM(sri.quantity), 0) as return_qty
            ")
            ->groupBy(DB::raw('DATE(sr.created_at)'))
            ->orderBy('d')
            ->get()
            ->keyBy('d');

        // -------------------------------
        // Per-day: sales (to compute return % of sales)
        // -------------------------------
        $salesBase = DB::table('sales as s')
            ->when($from,       fn($q) => $q->where('s.created_at', '>=', $from->copy()->startOfDay()))
            ->when($to,         fn($q) => $q->where('s.created_at', '<=', $to->copy()->endOfDay()))
            ->when($branchId,   fn($q) => $q->where('s.branch_id', $branchId))
            ->when($salesmanId, fn($q) => $q->where('s.salesman_id', $salesmanId))
            ->when($customerId, fn($q) => $q->where('s.customer_id', $customerId));

        $salesPerDay = (clone $salesBase)
            ->selectRaw("
                DATE(s.created_at) as d,
                COALESCE(SUM(s.total), 0) as sales_amount
            ")
            ->groupBy(DB::raw('DATE(s.created_at)'))
            ->orderBy('d')
            ->get()
            ->keyBy('d');

        // -------------------------------
        // Build date keys (dense or sparse)
        // -------------------------------
        if ($from && $to) {
            $dayKeys = [];
            foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
                $dayKeys[] = $day->toDateString();
            }
        } else {
            $dayKeys = collect($returnsPerDay->keys())
                ->merge($salesPerDay->keys())
                ->unique()
                ->sort()
                ->values()
                ->all();
        }

        $totalDays = count($dayKeys);
        $lastPage  = max(1, (int)ceil($totalDays / $perPage));
        $offset    = ($page - 1) * $perPage;
        $pagedKeys = array_slice($dayKeys, $offset, $perPage);

        // -------------------------------
        // Compose per-day rows + totals
        // -------------------------------
        $rows = [];
        $totReturnCount  = 0;
        $totReturnQty    = 0.0;
        $totReturnAmount = 0.0;
        $totSalesAmount  = 0.0;

        foreach ($pagedKeys as $d) {
            $r  = $returnsPerDay->get($d);
            $q  = $qtyPerDay->get($d);
            $sa = $salesPerDay->get($d);

            $returnCount  = $r ? (int)$r->return_count        : 0;
            $returnAmount = $r ? (float)$r->return_amount     : 0.0;
            $returnQty    = $q ? (float)$q->return_qty        : 0.0;
            $salesAmount  = $sa ? (float)$sa->sales_amount    : 0.0;

            $totReturnCount  += $returnCount;
            $totReturnQty    += $returnQty;
            $totReturnAmount += $returnAmount;
            $totSalesAmount  += $salesAmount;

            $avgReturnAmount = $returnCount > 0
                ? $returnAmount / $returnCount
                : 0.0;

            $returnRatio = $salesAmount > 0
                ? ($returnAmount / $salesAmount) * 100
                : 0.0;

            $rows[] = [
                'date'                => $d,
                'return_count'        => $returnCount,
                'return_qty'          => round($returnQty, 2),
                'return_amount'       => round($returnAmount, 2),
                'avg_return_amount'   => round($avgReturnAmount, 2),
                'sales_amount'        => round($salesAmount, 2),
                'return_ratio_percent'=> round($returnRatio, 2), // % of sales
            ];
        }

        $overallReturnRatio = $totSalesAmount > 0
            ? ($totReturnAmount / $totSalesAmount) * 100
            : 0.0;

        $perDayBlock = [
            'rows' => $rows,
            'totals' => [
                'return_count'         => $totReturnCount,
                'return_qty'           => round($totReturnQty, 2),
                'return_amount'        => round($totReturnAmount, 2),
                'sales_amount'         => round($totSalesAmount, 2),
                'return_ratio_percent' => round($overallReturnRatio, 2),
            ],
            'pagination' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'last_page'    => $lastPage,
                'total_days'   => $totalDays,
            ],
        ];

        // -------------------------------
        // Top returned products
        // -------------------------------
        // Assumes sale_return_items.product_id exists; if not, join via sale_items table.
        $byProduct = (clone $itemBase)
            ->join('products as p', 'p.id', '=', 'sri.product_id')
            ->selectRaw("
                p.id   as product_id,
                p.name as product_name,
                COUNT(DISTINCT sri.sale_return_id)        as return_count,
                COALESCE(SUM(sri.quantity), 0)            as return_qty,
                COALESCE(SUM(sri.total), 0)               as return_amount
            ")
            ->groupBy('p.id', 'p.name')
            ->orderByDesc('return_qty')
            ->limit(50)
            ->get()
            ->map(function ($r) {
                return [
                    'product_id'    => (int)$r->product_id,
                    'product_name'  => $r->product_name,
                    'return_count'  => (int)$r->return_count,
                    'return_qty'    => (float)$r->return_qty,
                    'return_amount' => round((float)$r->return_amount, 2),
                ];
            })
            ->all();

        // -------------------------------
        // Returns by reason
        // -------------------------------
        // Assumes sale_returns.reason column (string, nullable).
        $byReason = (clone $baseReturns)
            ->selectRaw("
                COALESCE(NULLIF(sr.reason, ''), '(No reason)') as reason,
                COUNT(sr.id)               as return_count,
                COALESCE(SUM(sr.total), 0) as return_amount
            ")
            ->groupBy('reason')
            ->orderByDesc('return_amount')
            ->get()
            ->map(function ($r) {
                return [
                    'reason'        => $r->reason,
                    'return_count'  => (int)$r->return_count,
                    'return_amount' => round((float)$r->return_amount, 2),
                ];
            })
            ->all();

        return [
            'per_day'   => $perDayBlock,
            'by_product'=> $byProduct,
            'by_reason' => $byReason,
            'filters'   => [
                'from'        => $from?->toDateString(),
                'to'          => $to?->toDateString(),
                'branch_id'   => $branchId,
                'salesman_id' => $salesmanId,
                'customer_id' => $customerId,
            ],
        ];
    }
}
