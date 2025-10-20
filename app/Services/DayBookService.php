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
        // Resolve dates (last 30 days inclusive, ending today)
        $to   = $toDate   ? Carbon::parse($toDate)->toDateString() : Carbon::today()->toDateString();
        $from = $fromDate ? Carbon::parse($fromDate)->toDateString() : Carbon::parse($to)->subDays(29)->toDateString();

        // Normalize if from > to
        if (Carbon::parse($from)->gt(Carbon::parse($to))) {
            [$from, $to] = [$to, $from];
        }

        // Sanitize pagination
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage)); // hard cap to avoid huge pages

        // Helper to apply optional branch filter
        $applyBranch = function ($q) use ($branchId) {
            if (!is_null($branchId)) $q->where('je.branch_id', $branchId);
            return $q;
        };

        // Opening net (cumulative In-Out) up to day before $from
        $openingRow = $applyBranch(
            DB::table('journal_postings as jp')
                ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'jp.account_id')
                ->join('account_types as at', 'at.id', '=', 'a.account_type_id')
        )
            ->where('je.entry_date', '<', $from)
            ->selectRaw("
            SUM(
              CASE at.code
                WHEN 'ASSET'     THEN jp.credit - jp.debit
                WHEN 'LIABILITY' THEN jp.credit - jp.debit
                WHEN 'EQUITY'    THEN jp.credit - jp.debit
                WHEN 'INCOME'    THEN jp.credit - jp.debit
                WHEN 'EXPENSE'   THEN jp.debit  - jp.credit
              END
            ) as net
        ")
            ->first();

        $opening = round((float)($openingRow->net ?? 0), 2);

        // Per-day IN/OUT within range
        $dailyRows = $applyBranch(
            DB::table('journal_postings as jp')
                ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'jp.account_id')
                ->join('account_types as at', 'at.id', '=', 'a.account_type_id')
        )
            ->whereBetween('je.entry_date', [$from, $to])
            ->groupBy('je.entry_date')
            ->orderBy('je.entry_date') // we build ascending first; we'll reorder later
            ->selectRaw("
          je.entry_date as d,
          SUM(CASE at.code
                WHEN 'ASSET'     THEN jp.credit
                WHEN 'LIABILITY' THEN jp.credit
                WHEN 'EQUITY'    THEN jp.credit
                WHEN 'INCOME'    THEN jp.credit
                ELSE 0 END)
          +
          SUM(CASE at.code WHEN 'EXPENSE' THEN jp.debit ELSE 0 END) as in_amt,

          SUM(CASE at.code
                WHEN 'ASSET'     THEN jp.debit
                WHEN 'LIABILITY' THEN jp.debit
                WHEN 'EQUITY'    THEN jp.debit
                WHEN 'INCOME'    THEN jp.debit
                ELSE 0 END)
          +
          SUM(CASE at.code WHEN 'EXPENSE' THEN jp.credit ELSE 0 END) as out_amt
        ")
            ->get();

        // Map results by date for quick lookup (ascending)
        $byDate = [];
        foreach ($dailyRows as $r) {
            $byDate[$r->d] = [
                'in'  => round((float)$r->in_amt, 2),
                'out' => round((float)$r->out_amt, 2),
            ];
        }

        // Build continuous list ascending (even if no activity), compute running closing
        $daysAsc = [];
        $cursor = Carbon::parse($from);
        $end    = Carbon::parse($to);
        $running = $opening;

        $totIn = 0.0;
        $totOut = 0.0;

        while ($cursor->lte($end)) {
            $dateStr = $cursor->toDateString();
            $in  = $byDate[$dateStr]['in']  ?? 0.00;
            $out = $byDate[$dateStr]['out'] ?? 0.00;
            $net = round($in - $out, 2);

            $dayOpening = $running;
            $running = round($running + $net, 2);

            $daysAsc[] = [
                'date'    => $dateStr,
                'opening' => $dayOpening,
                'in'      => $in,
                'out'     => $out,
                'net'     => $net,
                'closing' => $running,
            ];

            $totIn  = round($totIn  + $in, 2);
            $totOut = round($totOut + $out, 2);

            $cursor->addDay();
        }

        // Reorder as requested
        $ordered = (strtolower($order) === 'asc') ? $daysAsc : array_reverse($daysAsc);

        // Pagination over ordered days
        $totalDays = count($ordered);
        $lastPage  = max(1, (int) ceil($totalDays / $perPage));
        $current   = min($page, $lastPage);
        $offset    = ($current - 1) * $perPage;

        $pageDays  = array_slice($ordered, $offset, $perPage);

        // Page totals (in/out/net across the page slice)
        $pageIn  = 0.0;
        $pageOut = 0.0;
        foreach ($pageDays as $d) {
            $pageIn  = round($pageIn  + (float)$d['in'], 2);
            $pageOut = round($pageOut + (float)$d['out'], 2);
        }
        $pageNet = round($pageIn - $pageOut, 2);

        return [
            'branch_id' => $branchId,   // may be null (all branches)
            'from'      => $from,
            'to'        => $to,
            'opening'   => $opening,
            'totals'    => [
                'in'      => $totIn,
                'out'     => $totOut,
                'net'     => round($totIn - $totOut, 2),
                'closing' => $running, // closing of the last day (end of range)
            ],
            'page_totals' => [
                'in'  => $pageIn,
                'out' => $pageOut,
                'net' => $pageNet,
            ],
            'days'       => $pageDays, // returned in requested order (DESC by default)
            'pagination' => [
                'total'        => $totalDays,
                'per_page'     => $perPage,
                'current_page' => $current,
                'last_page'    => $lastPage,
            ],
            'order'      => strtolower($order) === 'asc' ? 'asc' : 'desc',
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
        $d = Carbon::parse($date)->toDateString();
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $order = strtolower($order) === 'desc' ? 'desc' : 'asc';

        $applyBranch = function ($q) use ($branchId) {
            if (!is_null($branchId)) $q->where('je.branch_id', $branchId);
            return $q;
        };

        // ---- Opening & Closing ------------------------------------------------

        // Opening net before the day
        $openingRow = $applyBranch(
            DB::table('journal_postings as jp')
                ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'jp.account_id')
                ->join('account_types as at', 'at.id', '=', 'a.account_type_id')
        )
            ->where('je.entry_date', '<', $d)
            ->selectRaw("
            SUM(
              CASE at.code
                WHEN 'ASSET'     THEN jp.credit - jp.debit
                WHEN 'LIABILITY' THEN jp.credit - jp.debit
                WHEN 'EQUITY'    THEN jp.credit - jp.debit
                WHEN 'INCOME'    THEN jp.credit - jp.debit
                WHEN 'EXPENSE'   THEN jp.debit  - jp.credit
              END
            ) as net
        ")
            ->first();

        $opening = round((float)($openingRow->net ?? 0), 2);

        // Day totals (in/out) for that date
        $dayTotals = $applyBranch(
            DB::table('journal_postings as jp')
                ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'jp.account_id')
                ->join('account_types as at', 'at.id', '=', 'a.account_type_id')
        )
            ->whereDate('je.entry_date', $d)
            ->selectRaw("
            SUM(
              CASE at.code
                WHEN 'ASSET'     THEN jp.credit
                WHEN 'LIABILITY' THEN jp.credit
                WHEN 'EQUITY'    THEN jp.credit
                WHEN 'INCOME'    THEN jp.credit
                ELSE 0 END
            )
            +
            SUM(CASE at.code WHEN 'EXPENSE' THEN jp.debit ELSE 0 END) as in_amt,

            SUM(
              CASE at.code
                WHEN 'ASSET'     THEN jp.debit
                WHEN 'LIABILITY' THEN jp.debit
                WHEN 'EQUITY'    THEN jp.debit
                WHEN 'INCOME'    THEN jp.debit
                ELSE 0 END
            )
            +
            SUM(CASE at.code WHEN 'EXPENSE' THEN jp.credit ELSE 0 END) as out_amt
        ")
            ->first();

        $totIn  = round((float)($dayTotals->in_amt  ?? 0), 2);
        $totOut = round((float)($dayTotals->out_amt ?? 0), 2);
        $totNet = round($totIn - $totOut, 2);
        $closing = round($opening + $totNet, 2);

        // ---- Base aggregated query by journal entry ---------------------------

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

        $agg = $base->groupBy('je.id','je.entry_date','je.created_at','je.memo','je.reference_type','je.reference_id')
            ->selectRaw("
                je.id,
                je.entry_date,
                je.created_at,
                je.memo,
                je.reference_type,
                je.reference_id,

                -- per-entry IN
                SUM(
                  CASE at.code
                    WHEN 'ASSET'     THEN jp.credit
                    WHEN 'LIABILITY' THEN jp.credit
                    WHEN 'EQUITY'    THEN jp.credit
                    WHEN 'INCOME'    THEN jp.credit
                    ELSE 0 END
                )
                +
                SUM(CASE at.code WHEN 'EXPENSE' THEN jp.debit ELSE 0 END) as in_amt,

                -- per-entry OUT
                SUM(
                  CASE at.code
                    WHEN 'ASSET'     THEN jp.debit
                    WHEN 'LIABILITY' THEN jp.debit
                    WHEN 'EQUITY'    THEN jp.debit
                    WHEN 'INCOME'    THEN jp.debit
                    ELSE 0 END
                )
                +
                SUM(CASE at.code WHEN 'EXPENSE' THEN jp.credit ELSE 0 END) as out_amt
            ");

        // Sorting
        switch ($sort) {
            case 'in':
                $agg->orderBy('in_amt', $order);
                break;
            case 'out':
                $agg->orderBy('out_amt', $order);
                break;
            case 'net':
                // net = in - out
                $agg->orderByRaw('(SUM(CASE at.code WHEN \'ASSET\' THEN jp.credit WHEN \'LIABILITY\' THEN jp.credit WHEN \'EQUITY\' THEN jp.credit WHEN \'INCOME\' THEN jp.credit ELSE 0 END) + SUM(CASE at.code WHEN \'EXPENSE\' THEN jp.debit ELSE 0 END) - (SUM(CASE at.code WHEN \'ASSET\' THEN jp.debit WHEN \'LIABILITY\' THEN jp.debit WHEN \'EQUITY\' THEN jp.debit WHEN \'INCOME\' THEN jp.debit ELSE 0 END) + SUM(CASE at.code WHEN \'EXPENSE\' THEN jp.credit ELSE 0 END))) ' . $order);
                break;
            case 'reference_type':
                $agg->orderBy('je.reference_type', $order)->orderBy('je.id', $order);
                break;
            case 'created_at':
            default:
                $agg->orderBy('je.created_at', $order)->orderBy('je.id', $order);
                break;
        }

        // Clone for count (count distinct je.id)
        $countQ = $applyBranch(
            DB::table('journal_entries as je')
        )
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

        // Optionally load account lines per journal entry (for UI drill)
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

        // Build payload rows
        $payloadRows = [];
        foreach ($rows as $r) {
            $in  = round((float)$r->in_amt, 2);
            $out = round((float)$r->out_amt, 2);
            $payloadRows[] = [
                'entry_id'       => (int)$r->id,
                'time'           => optional($r->created_at)->toDateTimeString(),
                'memo'           => $r->memo,
                'reference_type' => $r->reference_type,
                'reference_id'   => $r->reference_id,
                'in'             => $in,
                'out'            => $out,
                'net'            => round($in - $out, 2),
                'lines'          => $includeLines ? ($linesByJe[$r->id] ?? []) : null,
            ];
        }

        return [
            'date'    => $d,
            'branch_id' => $branchId,
            'opening' => $opening,
            'closing' => $closing,
            'totals'  => [
                'in'  => $totIn,
                'out' => $totOut,
                'net' => $totNet,
            ],
            'rows' => $payloadRows,
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
