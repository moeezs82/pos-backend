<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DayBookService
{
    /**
     * Day-wise Opening, In, Out, Net, Closing across ALL accounts.
     *
     * Params:
     * - branchId: nullable => all branches
     * - from/to:  nullable => last 30 days inclusive ending today
     * - page/perPage: pagination over days (returned in requested order; default DESC)
     * - order: 'desc' (default) or 'asc' for date order
     *
     * In/Out via account_types.code:
     *  ASSET:     Dr↑=Out, Cr↑=In
     *  LIABILITY: Cr↑=In,  Dr↑=Out
     *  EQUITY:    Cr↑=In,  Dr↑=Out
     *  INCOME:    Cr↑=In,  Dr↑=Out
     *  EXPENSE:   Dr↑=Out, Cr↑=In
     */
    public function summary(
        ?int $branchId = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        int $page = 1,
        int $perPage = 30,
        string $order = 'desc'
    ): array {
        $to   = $toDate   ? Carbon::parse($toDate)->toDateString() : Carbon::today()->toDateString();
        $from = $fromDate ? Carbon::parse($fromDate)->toDateString() : Carbon::parse($to)->subDays(29)->toDateString();
        if (Carbon::parse($from)->gt(Carbon::parse($to))) {
            [$from, $to] = [$to, $from];
        }

        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $applyBranch = function ($q) use ($branchId) {
            if (!is_null($branchId)) $q->where('je.branch_id', $branchId);
            return $q;
        };

        // ------------------ Identify Cash/Bank accounts ------------------
        // Option A: by explicit codes (edit to your chart)
        $cashCodes = ['1000', '1010']; // Cash in Hand, Bank
        // Option B (recommended): a boolean column accounts.is_cash
        // ->where('a.is_cash', 1)

        // ------------------ Opening CASH balance before $from ------------------
        $openingRow = $applyBranch(
            DB::table('journal_postings as jp')
                ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'jp.account_id')
        )
            ->where('je.entry_date', '<', $from)
            ->whereIn('a.code', $cashCodes) // or ->where('a.is_cash',1)
            ->selectRaw("COALESCE(SUM(jp.debit - jp.credit), 0) as cash_bal")
            ->first();

        $opening = round((float)($openingRow->cash_bal ?? 0), 2);

        // ------------------ Per-day CASH IN/OUT + EXPENSE within range ------------------
        $dailyRows = $applyBranch(
            DB::table('journal_postings as jp')
                ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'jp.account_id')
                ->join('account_types as at', 'at.id', '=', 'a.account_type_id')
        )
            ->whereBetween('je.entry_date', [$from, $to])
            ->groupBy('je.entry_date')
            ->orderBy('je.entry_date')
            ->selectRaw("
            je.entry_date as d,

            -- CASH IN: debits to cash/bank
            COALESCE(SUM(CASE WHEN a.code IN (" . implode(',', array_map(fn($c) => DB::getPdo()->quote($c), $cashCodes)) . ")
                               THEN jp.debit ELSE 0 END), 0) as in_amt,

            -- CASH OUT: credits to cash/bank
            COALESCE(SUM(CASE WHEN a.code IN (" . implode(',', array_map(fn($c) => DB::getPdo()->quote($c), $cashCodes)) . ")
                               THEN jp.credit ELSE 0 END), 0) as out_amt,

            -- EXPENSE total (for reporting)
            COALESCE(SUM(CASE WHEN at.code = 'EXPENSE' THEN jp.debit ELSE 0 END), 0) as expense_amt
        ")
            ->get();

        // Map by date
        $byDate = [];
        foreach ($dailyRows as $r) {
            $byDate[$r->d] = [
                'in'      => round((float)$r->in_amt, 2),
                'out'     => round((float)$r->out_amt, 2),
                'expense' => round((float)$r->expense_amt, 2),
            ];
        }

        // Build continuous days (ascending), compute running cash closing
        $daysAsc = [];
        $cursor  = Carbon::parse($from);
        $end     = Carbon::parse($to);
        $running = $opening;

        $totIn = $totOut = $totExp = 0.0;

        while ($cursor->lte($end)) {
            $dStr    = $cursor->toDateString();
            $in      = $byDate[$dStr]['in']      ?? 0.00;   // cash in (receipts)
            $out     = $byDate[$dStr]['out']     ?? 0.00;   // cash out (payments)
            $expense = $byDate[$dStr]['expense'] ?? 0.00;   // informational
            $netCash = round($in - $out, 2);                // cash movement (ignore expense here)

            $dayOpening = $running;
            $running    = round($running + $netCash, 2);

            $daysAsc[] = [
                'date'    => $dStr,
                'opening' => $dayOpening,
                'in'      => $in,
                'out'     => $out,
                'expense' => $expense,
                'net'     => round($in - ($out + 0), 2), // if you want to show cash net only
                'closing' => $running,
            ];

            $totIn  = round($totIn  + $in, 2);
            $totOut = round($totOut + $out, 2);
            $totExp = round($totExp + $expense, 2);

            $cursor->addDay();
        }

        // Order and paginate
        $ordered   = (strtolower($order) === 'asc') ? $daysAsc : array_reverse($daysAsc);
        $totalDays = count($ordered);
        $lastPage  = max(1, (int)ceil($totalDays / $perPage));
        $current   = min($page, $lastPage);
        $offset    = ($current - 1) * $perPage;
        $pageDays  = array_slice($ordered, $offset, $perPage);

        $pageIn = $pageOut = $pageExp = 0.0;
        foreach ($pageDays as $dRow) {
            $pageIn  = round($pageIn  + (float)$dRow['in'], 2);
            $pageOut = round($pageOut + (float)$dRow['out'], 2);
            $pageExp = round($pageExp + (float)$dRow['expense'], 2);
        }
        $pageNet = round($pageIn - $pageOut, 2); // cash net on the page slice

        return [
            'branch_id' => $branchId,
            'from'      => $from,
            'to'        => $to,
            'opening'   => $opening,

            'totals' => [
                'in'      => $totIn,
                'out'     => $totOut,
                'expense' => $totExp,
                'net'     => round($totIn - $totOut, 2), // cash net
                'closing' => $running,                   // cash closing at end
            ],
            'page_totals' => [
                'in'      => $pageIn,
                'out'     => $pageOut,
                'expense' => $pageExp,
                'net'     => $pageNet,
            ],
            'days' => $pageDays,
            'pagination' => [
                'total'        => $totalDays,
                'per_page'     => $perPage,
                'current_page' => $current,
                'last_page'    => $lastPage,
            ],
            'order' => strtolower($order) === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * Detailed transactions for a given date (journal-entry level),
     * with opening/closing and in/out totals.
     *
     * @param int|null  $branchId
     * @param string    $date         Y-m-d (required)
     * @param int       $page         default 1
     * @param int       $perPage      default 100 (cap 500)
     * @param string    $sort         created_at|in|out|net|reference_type (default: created_at)
     * @param string    $order        asc|desc (default: asc)
     * @param string|null $referenceType  optional: filter by morph class e.g. "App\\Models\\Sale"
     * @param string|null $search     optional: searches je.memo OR reference_id
     * @param bool      $includeLines include accounts (jp) per row
     */
    public function details(
        ?int $branchId,
        string $date,
        int $page = 1,
        int $perPage = 100,
        string $sort = 'created_at',
        string $order = 'asc',
        ?string $referenceType = null,
        ?string $search = null,
        bool $includeLines = true
    ): array {
        $d       = Carbon::parse($date)->toDateString();
        $page    = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $order   = strtolower($order) === 'desc' ? 'desc' : 'asc';

        $applyBranch = function ($q) use ($branchId) {
            if (!is_null($branchId)) $q->where('je.branch_id', $branchId);
            return $q;
        };

        // ------------------ Identify Cash/Bank accounts ------------------
        // Option A: by codes
        $cashCodes = ['1000', '1010']; // Cash in Hand, Bank
        // Option B: use a flag column instead (recommended):
        //   ->where('a.is_cash', 1)

        // Prebuild quoted list for selectRaw IN (...)
        $quotedList = implode(',', array_map(fn($c) => DB::getPdo()->quote($c), $cashCodes));

        // ------------------ Opening CASH balance (before the day) ------------------
        $openingRow = $applyBranch(
            DB::table('journal_postings as jp')
                ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'jp.account_id')
        )
            ->where('je.entry_date', '<', $d)
            ->whereIn('a.code', $cashCodes) // or ->where('a.is_cash', 1)
            ->selectRaw("COALESCE(SUM(jp.debit - jp.credit), 0) as cash_bal")
            ->first();

        $opening = round((float)($openingRow->cash_bal ?? 0), 2);

        // ------------------ Day totals (cash IN/OUT + EXPENSE) ------------------
        $dayTotals = $applyBranch(
            DB::table('journal_postings as jp')
                ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'jp.account_id')
                ->join('account_types as at', 'at.id', '=', 'a.account_type_id')
        )
            ->whereDate('je.entry_date', $d)
            ->selectRaw("
            -- CASH IN: debits to cash/bank
            COALESCE(SUM(CASE WHEN a.code IN ($quotedList) THEN jp.debit  ELSE 0 END), 0) as in_amt,
            -- CASH OUT: credits to cash/bank
            COALESCE(SUM(CASE WHEN a.code IN ($quotedList) THEN jp.credit ELSE 0 END), 0) as out_amt,
            -- EXPENSE: debits to expense accounts (informational)
            COALESCE(SUM(CASE WHEN at.code = 'EXPENSE' THEN jp.debit ELSE 0 END), 0) as expense_amt
        ")
            ->first();

        $totIn   = round((float)($dayTotals->in_amt      ?? 0), 2);
        $totOut  = round((float)($dayTotals->out_amt     ?? 0), 2);
        $totExp  = round((float)($dayTotals->expense_amt ?? 0), 2);
        $totNet  = round($totIn - $totOut, 2); // cash net
        $closing = round($opening + $totNet, 2);

        // ------------------ Per-entry aggregation (same cash logic) ------------------
        $base = $applyBranch(
            DB::table('journal_postings as jp')
                ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'jp.account_id')
                ->join('account_types as at', 'at.id', '=', 'a.account_type_id')
        )
            ->whereDate('je.entry_date', $d);

        if ($referenceType) {
            $base->where('je.reference_type', $referenceType);
        }
        if ($search) {
            $base->where(function ($q) use ($search) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                $q->where('je.memo', 'like', $like)
                    ->orWhere('je.reference_id', 'like', $like);
            });
        }

        $agg = $base
            ->groupBy('je.id', 'je.entry_date', 'je.created_at', 'je.memo', 'je.reference_type', 'je.reference_id')
            ->selectRaw("
            je.id,
            je.entry_date,
            je.created_at,
            je.memo,
            je.reference_type,
            je.reference_id,

            -- per-entry CASH IN
            COALESCE(SUM(CASE WHEN a.code IN ($quotedList) THEN jp.debit  ELSE 0 END), 0) as in_amt,
            -- per-entry CASH OUT
            COALESCE(SUM(CASE WHEN a.code IN ($quotedList) THEN jp.credit ELSE 0 END), 0) as out_amt,
            -- per-entry EXPENSE
            COALESCE(SUM(CASE WHEN at.code = 'EXPENSE' THEN jp.debit ELSE 0 END), 0) as expense_amt
        ");

        // Sorting
        switch ($sort) {
            case 'in':
                $agg->orderBy('in_amt', $order);
                break;
            case 'out':
                $agg->orderBy('out_amt', $order);
                break;
            case 'expense':
                $agg->orderBy('expense_amt', $order);
                break;
            case 'net':
                // cash net = in - out
                $agg->orderByRaw('(in_amt - out_amt) ' . $order);
                break;
            case 'reference_type':
                $agg->orderBy('je.reference_type', $order)->orderBy('je.id', $order);
                break;
            case 'created_at':
            default:
                $agg->orderBy('je.created_at', $order)->orderBy('je.id', $order);
                break;
        }

        // Count distinct entries
        $countQ = $applyBranch(DB::table('journal_entries as je'))
            ->whereDate('je.entry_date', $d);
        if ($referenceType) $countQ->where('je.reference_type', $referenceType);
        if ($search) {
            $countQ->where(function ($q) use ($search) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                $q->where('je.memo', 'like', $like)
                    ->orWhere('je.reference_id', 'like', $like);
            });
        }
        $total = (int) $countQ->count('je.id');

        // Pagination
        $lastPage = max(1, (int)ceil($total / $perPage));
        $current  = min($page, $lastPage);
        $offset   = ($current - 1) * $perPage;

        $rows = $agg->offset($offset)->limit($perPage)->get();

        // Lines for drilldown
        $linesByJe = [];
        if ($includeLines && $rows->count()) {
            $ids = $rows->pluck('id')->all();
            $lines = DB::table('journal_postings as jp')
                ->join('accounts as a', 'a.id', '=', 'jp.account_id')
                ->join('account_types as at', 'at.id', '=', 'a.account_type_id')
                ->whereIn('jp.journal_entry_id', $ids)
                ->selectRaw("
                jp.journal_entry_id,
                jp.account_id,
                a.code as account_code,
                a.name as account_name,
                at.code as account_type,
                jp.debit,
                jp.credit
            ")
                ->orderBy('jp.journal_entry_id')
                ->orderBy('a.code')
                ->get()
                ->groupBy('journal_entry_id');

            foreach ($lines as $jeId => $list) {
                $linesByJe[$jeId] = $list->map(function ($r) {
                    return [
                        'account_id'   => (int)$r->account_id,
                        'account_code' => $r->account_code,
                        'account_name' => $r->account_name,
                        'account_type' => $r->account_type,
                        'debit'        => round((float)$r->debit, 2),
                        'credit'       => round((float)$r->credit, 2),
                    ];
                })->values()->all();
            }
        }

        // Build payload
        $payloadRows = [];
        foreach ($rows as $r) {
            $in  = round((float)$r->in_amt, 2);
            $out = round((float)$r->out_amt, 2);
            $exp = round((float)$r->expense_amt, 2);
            $payloadRows[] = [
                'entry_id'       => (int)$r->id,
                'time'           => optional($r->created_at)->toDateTimeString(),
                'memo'           => $r->memo,
                'reference_type' => $r->reference_type,
                'reference_id'   => $r->reference_id,
                'in'             => $in,
                'out'            => $out,
                'expense'        => $exp,                  // informational
                'net'            => round($in - $out, 2),  // cash net (matches summary)
                'lines'          => $includeLines ? ($linesByJe[$r->id] ?? []) : null,
            ];
        }

        return [
            'date'      => $d,
            'branch_id' => $branchId,
            'opening'   => $opening,
            'closing'   => $closing,
            'totals'    => [
                'in'      => $totIn,
                'out'     => $totOut,
                'expense' => $totExp,
                'net'     => $totNet, // cash net for the day
            ],
            'rows'       => $payloadRows,
            'pagination' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $current,
                'last_page'    => $lastPage,
            ],
            'sort'  => $sort,
            'order' => $order,
        ];
    }
}
