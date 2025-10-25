<?php

// app/Services/SalesReportService.php
namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesReportService
{
    private function range($q, ?Carbon $from, ?Carbon $to, string $col = 'je.entry_date')
    {
        if ($from) $q->where($col, '>=', $from->copy()->startOfDay()->toDateString());
        if ($to)   $q->where($col, '<=', $to->copy()->endOfDay()->toDateString());
        return $q;
    }

    private function basePostingQ()
    {
        return DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id');
    }

    private function joinSaleMeta($q, ?int $registerId, ?int $cashierId, ?string $channel)
    {
        // Only available when JE references Sale
        $q->leftJoin('sales as s', function ($j) {
            $j->on('s.id', '=', 'je.reference_id')
                ->where('je.reference_type', '=', 'App\\Models\\Sale');
        });
        if ($registerId) $q->where('s.register_id', $registerId);
        if ($cashierId)  $q->where('s.cashier_id', $cashierId);
        if ($channel)    $q->where('s.channel', $channel);
        return $q;
    }

    private function filterAll($q, ?Carbon $from, ?Carbon $to, ?int $branchId, ?int $registerId, ?int $cashierId, ?string $channel)
    {
        $this->range($q, $from, $to);
        if ($branchId) $q->where('je.branch_id', $branchId);
        $this->joinSaleMeta($q, $registerId, $cashierId, $channel);
        return $q;
    }

    /** Resolve account IDs by explicit codes; if empty, fallback to type/name_like */
    private function accountIds(string $key): array
    {
        $codes = config("reports.accounts.$key", []);
        if (!empty($codes)) {
            return DB::table('accounts')->whereIn('code', $codes)->pluck('id')->all();
        }

        $fb = config("reports.fallbacks.$key");
        if (!$fb) return [];
        $q = DB::table('accounts as a')
            ->join('account_types as t', 't.id', '=', 'a.account_type_id')
            ->where('t.code', $fb['type']);
        if (!empty($fb['name_like'])) {
            $q->where('a.name', 'like', '%' . $fb['name_like'] . '%');
        }
        return $q->pluck('a.id')->all();
    }

    /** ---------------- Daily Sales Summary ---------------- */
    public function dailySummary(
        ?Carbon $from,
        ?Carbon $to,
        ?int $branchId,
        ?int $salesmanId,
        ?int $customerId
    ): array {
        // ------------ Base SALES aggregates ------------
        $salesQ = DB::table('sales')
            ->when($from, fn($q) => $q->where('created_at', '>=', $from->copy()->startOfDay()))
            ->when($to,   fn($q) => $q->where('created_at', '<=', $to->copy()->endOfDay()))
            ->when($branchId,   fn($q) => $q->where('branch_id', $branchId))
            ->when($salesmanId, fn($q) => $q->where('salesman_id', $salesmanId))
            ->when($customerId, fn($q) => $q->where('customer_id', $customerId));

        // Sum needed columns from sales
        $salesAgg = (clone $salesQ)->selectRaw("
                COALESCE(SUM(subtotal), 0) AS subtotal_sum,
                COALESCE(SUM(discount), 0) AS discount_sum,
                COALESCE(SUM(tax), 0)      AS tax_sum,
                COALESCE(SUM(total), 0)    AS total_sum
            ")->first();

        $gross   = (float)($salesAgg->subtotal_sum ?? 0.0);   // gross sales (before discounts & tax)
        $disc    = (float)($salesAgg->discount_sum ?? 0.0);   // discounts given
        $tax     = (float)($salesAgg->tax_sum ?? 0.0);        // output tax on sales
        $total   = (float)($salesAgg->total_sum ?? 0.0);      // net after discount+tax (pre-returns)

        // ------------ RETURNS aggregates ------------
        // Join to sales so returns respect same branch/salesman/customer filters
        $returnsQ = DB::table('sale_returns as sr')
            ->join('sales as s', 's.id', '=', 'sr.sale_id')
            ->when($from, fn($q) => $q->where('sr.created_at', '>=', $from->copy()->startOfDay()))
            ->when($to,   fn($q) => $q->where('sr.created_at', '<=', $to->copy()->endOfDay()))
            ->when($branchId,   fn($q) => $q->where('s.branch_id', $branchId))
            ->when($salesmanId, fn($q) => $q->where('s.salesman_id', $salesmanId))
            ->when($customerId, fn($q) => $q->where('s.customer_id', $customerId));

        $returns = (float) ((clone $returnsQ)
            ->selectRaw("COALESCE(SUM(sr.total), 0) AS returns_total")
            ->value('returns_total') ?? 0.0);

        // ------------ Final KPIs ------------
        // Net sales after returns: (subtotal - discount + tax) - returns
        $net = $total - $returns;

        return [
            'gross_sales' => round($gross, 2),   // from sales.subtotal
            'discounts'   => round($disc, 2),    // from sales.discount
            'returns'     => round($returns, 2), // from sale_returns.total (positive)
            'tax'         => round($tax, 2),     // from sales.tax
            'net_sales'   => round($net, 2),     // total - returns
        ];
    }

    /**
     * Day-wise Daily Sales Summary (paginated)
     * - Filters: branch_id, salesman_id, customer_id
     * - Groups by DATE(created_at)
     * - Pagination: page/perPage (perPage hard-capped to 30)
     *
     * @return array{
     *   days: array<int, array{date:string,gross:float,discounts:float,tax:float,returns:float,net:float}>,
     *   page_totals: array{gross:float,discounts:float,tax:float,returns:float,net:float},
     *   grand_totals: array{gross:float,discounts:float,tax:float,returns:float,net:float},
     *   pagination: array{current_page:int, per_page:int, last_page:int, total_days:int}
     * }
     */
    public function dailySummaryByDay(
        ?Carbon $from,
        ?Carbon $to,
        ?int $branchId,
        ?int $salesmanId,
        ?int $customerId,
        int $page = 1,
        int $perPage = 30
    ): array {
        $page    = max(1, (int)$page);
        $perPage = max(1, min(30, (int)$perPage)); // hard cap at 30

        // ---------- SALES aggregated per day ----------
        $salesQ = DB::table('sales')
            ->when($from,       fn($q) => $q->where('created_at', '>=', $from->copy()->startOfDay()))
            ->when($to,         fn($q) => $q->where('created_at', '<=', $to->copy()->endOfDay()))
            ->when($branchId,   fn($q) => $q->where('branch_id', $branchId))
            ->when($salesmanId, fn($q) => $q->where('salesman_id', $salesmanId))
            ->when($customerId, fn($q) => $q->where('customer_id', $customerId));
        // ->whereIn('status', ['approved','posted']);

        $salesPerDay = (clone $salesQ)
            ->selectRaw("
            DATE(created_at) as d,
            COALESCE(SUM(subtotal), 0) AS subtotal_sum,
            COALESCE(SUM(discount), 0) AS discount_sum,
            COALESCE(SUM(tax), 0)      AS tax_sum,
            COALESCE(SUM(total), 0)    AS total_sum
        ")
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('d')
            ->get()
            ->keyBy('d'); // map 'YYYY-MM-DD' => row

        // ---------- RETURNS aggregated per day ----------
        $retQ = DB::table('sale_returns as sr')
            ->join('sales as s', 's.id', '=', 'sr.sale_id')
            ->when($from,       fn($q) => $q->where('sr.created_at', '>=', $from->copy()->startOfDay()))
            ->when($to,         fn($q) => $q->where('sr.created_at', '<=', $to->copy()->endOfDay()))
            ->when($branchId,   fn($q) => $q->where('s.branch_id', $branchId))
            ->when($salesmanId, fn($q) => $q->where('s.salesman_id', $salesmanId))
            ->when($customerId, fn($q) => $q->where('s.customer_id', $customerId));
        // ->whereIn('sr.status', ['approved','posted']);

        $returnsPerDay = (clone $retQ)
            ->selectRaw("DATE(sr.created_at) as d, COALESCE(SUM(sr.total),0) as returns_total")
            ->groupBy(DB::raw('DATE(sr.created_at)'))
            ->orderBy('d')
            ->get()
            ->keyBy('d');

        // ---------- Build full date list ----------
        if ($from && $to) {
            $dayKeys = [];
            foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
                $dayKeys[] = $day->toDateString();
            }
        } else {
            $dayKeys = collect($salesPerDay->keys())
                ->merge($returnsPerDay->keys())
                ->unique()
                ->sort()
                ->values()
                ->all();
        }

        $totalDays = count($dayKeys);
        $lastPage  = max(1, (int)ceil($totalDays / $perPage));
        $offset    = ($page - 1) * $perPage;
        $pagedKeys = array_slice($dayKeys, $offset, $perPage);

        // ---------- Compose rows (page) + totals ----------
        $rows = [];
        $pageGross = $pageDisc = $pageTax = $pageRet = $pageNet = 0.0;
        $grandGross = $grandDisc = $grandTax = $grandRet = $grandNet = 0.0;

        // Grand totals computed over ALL days
        foreach ($dayKeys as $d) {
            $s   = $salesPerDay->get($d);
            $r   = $returnsPerDay->get($d);

            $gross = $s ? (float)$s->subtotal_sum : 0.0;
            $disc  = $s ? (float)$s->discount_sum : 0.0;
            $tax   = $s ? (float)$s->tax_sum      : 0.0;
            $total = $s ? (float)$s->total_sum    : 0.0;
            $ret   = $r ? (float)$r->returns_total : 0.0;
            $net   = $total - $ret;

            $grandGross += $gross;
            $grandDisc  += $disc;
            $grandTax   += $tax;
            $grandRet   += $ret;
            $grandNet   += $net;
        }

        // Page rows + page totals only for current slice
        foreach ($pagedKeys as $d) {
            $s   = $salesPerDay->get($d);
            $r   = $returnsPerDay->get($d);

            $gross = $s ? (float)$s->subtotal_sum : 0.0;
            $disc  = $s ? (float)$s->discount_sum : 0.0;
            $tax   = $s ? (float)$s->tax_sum      : 0.0;
            $total = $s ? (float)$s->total_sum    : 0.0;
            $ret   = $r ? (float)$r->returns_total : 0.0;
            $net   = $total - $ret;

            $rows[] = [
                'date'      => $d,
                'gross'     => round($gross, 2),
                'discounts' => round($disc, 2),
                'tax'       => round($tax, 2),
                'returns'   => round($ret, 2),
                'net'       => round($net, 2),
            ];

            $pageGross += $gross;
            $pageDisc  += $disc;
            $pageTax   += $tax;
            $pageRet   += $ret;
            $pageNet   += $net;
        }

        return [
            'days' => $rows,
            'page_totals' => [
                'gross'     => round($pageGross, 2),
                'discounts' => round($pageDisc, 2),
                'tax'       => round($pageTax, 2),
                'returns'   => round($pageRet, 2),
                'net'       => round($pageNet, 2),
            ],
            'grand_totals' => [
                'gross'     => round($grandGross, 2),
                'discounts' => round($grandDisc, 2),
                'tax'       => round($grandTax, 2),
                'returns'   => round($grandRet, 2),
                'net'       => round($grandNet, 2),
            ],
            'pagination' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'last_page'    => $lastPage,
                'total_days'   => $totalDays,
            ],
        ];
    }

    /**
     * Top/Bottom Products (paginated)
     * - Sales (+) from sale_items
     * - Returns (–) from sale_return_items
     * - Filters: date range (created_at), branch_id, salesman_id, customer_id, category_id, vendor_id
     * - Sorting: revenue | margin | qty (desc by default)
     * - Pagination: page/perPage (defaults 20)
     *
     * @return array{
     *   rows: array<int, array{
     *     product_id:int,
     *     name?:string,
     *     sku?:string,
     *     qty:float,
     *     revenue:float,
     *     cogs:float,
     *     margin:float,
     *     refund_qty:float,
     *     refund_rate:float
     *   }>,
     *   totals: array{qty:float,revenue:float,cogs:float,margin:float,refund_qty:float},
     *   pagination: array{current_page:int, per_page:int, last_page:int, total_products:int}
     * }
     */
    public function topBottomProducts(
        ?Carbon $from,
        ?Carbon $to,
        ?int $branchId,
        ?int $salesmanId,
        ?int $customerId,
        ?int $categoryId,
        ?int $vendorId,
        string $sortBy = 'revenue',   // 'revenue' | 'margin' | 'qty'
        string $direction = 'desc',   // 'asc' | 'desc'
        int $page = 1,
        int $perPage = 20
    ): array {
        $page    = max(1, (int)$page);
        $perPage = max(1, min(100, (int)$perPage));
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $sortCol = in_array($sortBy, ['qty', 'margin', 'revenue'], true) ? $sortBy : 'revenue';

        // Cost basis: prefer unit_cost if column exists, else ps.avg_cost (branch), else products.cost_price
        $hasSIUnitCost  = Schema::hasColumn('sale_items', 'unit_cost');
        $hasSRIUnitCost = Schema::hasColumn('sale_return_items', 'unit_cost');

        $costExprPos = $hasSIUnitCost
            ? 'COALESCE(si.unit_cost, ps.avg_cost, p.cost_price)'
            : 'COALESCE(ps.avg_cost, p.cost_price)';

        $costExprNeg = Schema::hasTable('sale_return_items')
            ? ($hasSRIUnitCost
                ? 'COALESCE(sri.unit_cost, ps2.avg_cost, p.cost_price)'
                : 'COALESCE(ps2.avg_cost, p.cost_price)')
            : 'p.cost_price';

        // -------- Positive movements: Sales --------
        $pos = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            // branch-scoped avg_cost
            ->leftJoin('product_stocks as ps', function ($j) {
                $j->on('ps.product_id', '=', 'si.product_id')->on('ps.branch_id', '=', 's.branch_id');
            })
            ->when($from,       fn($q) => $q->where('si.created_at', '>=', $from->copy()->startOfDay()))
            ->when($to,         fn($q) => $q->where('si.created_at', '<=', $to->copy()->endOfDay()))
            ->when($branchId,   fn($q) => $q->where('s.branch_id', $branchId))
            ->when($salesmanId, fn($q) => $q->where('s.salesman_id', $salesmanId))
            ->when($customerId, fn($q) => $q->where('s.customer_id', $customerId))
            ->when($categoryId, fn($q) => $q->where('p.category_id', $categoryId))
            ->when($vendorId,   fn($q) => $q->where('p.vendor_id', $vendorId))
            ->selectRaw("
            si.product_id,
            SUM(si.quantity)                                     as qty_pos,
            SUM(si.quantity * si.price)                          as rev_pos,
            SUM(si.quantity * {$costExprPos})                    as cogs_pos
            ")
            ->groupBy('si.product_id');

        // -------- Negative movements: Returns --------
        $hasReturnItems = Schema::hasTable('sale_return_items');
        if ($hasReturnItems) {
            $ret = DB::table('sale_return_items as sri')
                ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
                ->join('sales as s', 's.id', '=', 'sr.sale_id') // to apply same filters
                ->join('products as p', 'p.id', '=', 'sri.product_id')
                ->leftJoin('product_stocks as ps2', function ($j) {
                    $j->on('ps2.product_id', '=', 'sri.product_id')->on('ps2.branch_id', '=', 's.branch_id');
                })
                ->when($from,       fn($q) => $q->where('sri.created_at', '>=', $from->copy()->startOfDay()))
                ->when($to,         fn($q) => $q->where('sri.created_at', '<=', $to->copy()->endOfDay()))
                ->when($branchId,   fn($q) => $q->where('s.branch_id', $branchId))
                ->when($salesmanId, fn($q) => $q->where('s.salesman_id', $salesmanId))
                ->when($customerId, fn($q) => $q->where('s.customer_id', $customerId))
                ->when($categoryId, fn($q) => $q->where('p.category_id', $categoryId))
                ->when($vendorId,   fn($q) => $q->where('p.vendor_id', $vendorId))
                ->selectRaw("
                sri.product_id,
                SUM(sri.quantity)                                 as qty_neg,
                SUM(sri.quantity * sri.price)                     as rev_neg,
                SUM(sri.quantity * {$costExprNeg})                as cogs_neg
            ")
                ->groupBy('sri.product_id');
        }

        // -------- Merge Sales(+) and Returns(–) --------
        // -------- Merge Sales(+) and Returns(–) --------
        $merged = DB::query()->fromSub(function ($sub) use ($pos, $ret, $hasReturnItems) {
            // Use fromSub to alias the first subquery as "pos"
            $sub->fromSub($pos, 'pos');

            if ($hasReturnItems) {
                $sub->leftJoinSub($ret, 'ret', 'ret.product_id', '=', 'pos.product_id');
                // IMPORTANT: Select only the columns we need and avoid duplicate product_id
                $sub->selectRaw("
                    pos.product_id,
                    COALESCE(pos.qty_pos,0)  as qty_pos,
                    COALESCE(pos.rev_pos,0)  as rev_pos,
                    COALESCE(pos.cogs_pos,0) as cogs_pos,
                    COALESCE(ret.qty_neg,0)  as qty_neg,
                    COALESCE(ret.rev_neg,0)  as rev_neg,
                    COALESCE(ret.cogs_neg,0) as cogs_neg
                ");
            } else {
                $sub->leftJoin(
                    DB::raw('(select 0 as product_id, 0 as qty_neg, 0 as rev_neg, 0 as cogs_neg) ret'),
                    'ret.product_id',
                    '=',
                    'pos.product_id'
                );
                $sub->selectRaw("
                    pos.product_id,
                    COALESCE(pos.qty_pos,0)  as qty_pos,
                    COALESCE(pos.rev_pos,0)  as rev_pos,
                    COALESCE(pos.cogs_pos,0) as cogs_pos,
                    0 as qty_neg,
                    0 as rev_neg,
                    0 as cogs_neg
                ");
            }
        }, 'x')->selectRaw("
                x.product_id,
                (x.qty_pos  - x.qty_neg)  as qty,
                (x.rev_pos  - x.rev_neg)  as revenue,
                (x.cogs_pos - x.cogs_neg) as cogs
            ");

        // Pull all rows once; aggregate per product is small enough to sort/paginate in memory
        $allRows = $merged->get()->map(function ($r) {
            $r->margin = (float)$r->revenue - (float)$r->cogs;
            return $r;
        });

        // ---- Sort + paginate (PHP 7/8-safe) ----
        $sorted = ($direction === 'desc')
            ? $allRows->sortByDesc($sortCol)->values()
            : $allRows->sortBy($sortCol, SORT_REGULAR, false)->values();

        $totalProducts = $sorted->count();
        $lastPage      = max(1, (int)ceil($totalProducts / $perPage));
        $slice         = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        // ---- Build refund stats for rate ----
        // sold qty (positive side only) and returned qty (across filtered date)
        $posQtyQ = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->when($from,       fn($q) => $q->where('si.created_at', '>=', $from->copy()->startOfDay()))
            ->when($to,         fn($q) => $q->where('si.created_at', '<=', $to->copy()->endOfDay()))
            ->when($branchId,   fn($q) => $q->where('s.branch_id', $branchId))
            ->when($salesmanId, fn($q) => $q->where('s.salesman_id', $salesmanId))
            ->when($customerId, fn($q) => $q->where('s.customer_id', $customerId));
        $soldQtyIndex = $posQtyQ->selectRaw('si.product_id, SUM(si.quantity) as sq')->groupBy('si.product_id')->pluck('sq', 'si.product_id');

        $refundIndex = collect();
        if ($hasReturnItems) {
            $retQtyQ = DB::table('sale_return_items as sri')
                ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
                ->join('sales as s', 's.id', '=', 'sr.sale_id')
                ->when($from,       fn($q) => $q->where('sri.created_at', '>=', $from->copy()->startOfDay()))
                ->when($to,         fn($q) => $q->where('sri.created_at', '<=', $to->copy()->endOfDay()))
                ->when($branchId,   fn($q) => $q->where('s.branch_id', $branchId))
                ->when($salesmanId, fn($q) => $q->where('s.salesman_id', $salesmanId))
                ->when($customerId, fn($q) => $q->where('s.customer_id', $customerId));
            $refundIndex = $retQtyQ->selectRaw('sri.product_id, SUM(sri.quantity) as rq')->groupBy('sri.product_id')->pluck('rq', 'sri.product_id');
        }

        // ---- Bulk-load product name/sku for current page ----
        $pageIds = $slice->pluck('product_id')->unique()->all();
        $nameById = DB::table('products')->whereIn('id', $pageIds)->pluck('name', 'id');
        $skuById  = DB::table('products')->whereIn('id', $pageIds)->pluck('sku', 'id');

        $rows = $slice->map(function ($r) use ($soldQtyIndex, $refundIndex, $nameById, $skuById) {
            $pid  = (int)$r->product_id;
            $sold = (float)($soldQtyIndex[$pid] ?? 0);
            $ret  = (float)($refundIndex[$pid] ?? 0);
            $rate = $sold > 0 ? round($ret / $sold, 4) : 0.0;

            return [
                'product_id' => $pid,
                'name'       => (string)($nameById[$pid] ?? ''),
                'sku'        => $skuById[$pid] ?? null,
                'qty'        => round((float)$r->qty, 2),
                'revenue'    => round((float)$r->revenue, 2),
                'cogs'       => round((float)$r->cogs, 2),
                'margin'     => round(((float)$r->revenue - (float)$r->cogs), 2),
                'refund_qty' => round($ret, 2),
                'refund_rate' => $rate,
            ];
        })->all();

        // Totals over full filtered set (not just page)
        $totQty  = round((float)$allRows->sum('qty'), 2);
        $totRev  = round((float)$allRows->sum('revenue'), 2);
        $totCogs = round((float)$allRows->sum('cogs'), 2);
        $totMarg = round($totRev - $totCogs, 2);
        $totRetQ = round((float)$refundIndex->sum(), 2);

        return [
            'rows' => $rows,
            'totals' => [
                'qty'        => $totQty,
                'revenue'    => $totRev,
                'cogs'       => $totCogs,
                'margin'     => $totMarg,
                'refund_qty' => $totRetQ,
            ],
            'pagination' => [
                'current_page'  => $page,
                'per_page'      => $perPage,
                'last_page'     => max(1, (int)ceil($totalProducts / $perPage)),
                'total_products' => $totalProducts,
            ],
        ];
    }
}
