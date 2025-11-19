<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProfitLossService
{
    /**
     * Profit & Loss summary for a date range (optionally branch-limited).
     *
     * Params (array $p):
     * - from?: Carbon|string|null  (Y-m-d)  // inclusive
     * - to?:   Carbon|string|null  (Y-m-d)  // inclusive
     * - branch_id?: int|null
     *
     * Returns:
     * - from, to, branch_id
     * - sections: [
     *      income:       { rows:[], total:float },
     *      cogs:         { rows:[], total:float },
     *      expenses:     { rows:[], total:float },
     *      gross_profit: float,
     *      net_profit:   float,
     *   ]
     */
    public function summary(array $p): array
    {
        // ---------- Normalize dates ----------
        $from = isset($p['from']) && $p['from']
            ? ($p['from'] instanceof Carbon ? $p['from']->copy()->startOfDay() : Carbon::parse($p['from'])->startOfDay())
            : null;

        $to = isset($p['to']) && $p['to']
            ? ($p['to'] instanceof Carbon ? $p['to']->copy()->endOfDay() : Carbon::parse($p['to'])->endOfDay())
            : null;

        if ($from && $to && $from->gt($to)) {
            throw new InvalidArgumentException('`from` must be on or before `to`.');
        }

        $branchId = isset($p['branch_id']) ? (int)$p['branch_id'] : null;

        // Only INCOME + EXPENSE exist in your account_types for P&L
        $typeCodes = ['INCOME', 'EXPENSE'];

        // Basic mapping (we will further split some EXPENSE into COGS below)
        $typeMapToSection = [
            'INCOME'  => 'income',
            'EXPENSE' => 'expenses',
        ];

        // ---------- Base query (GL) ----------
        $effDateExpr = "COALESCE(jp.created_at, je.entry_date, je.created_at)";

        $q = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jp.account_id')
            ->join('account_types as t', 't.id', '=', 'a.account_type_id')
            ->whereIn('t.code', $typeCodes);

        if ($from) {
            $q->whereRaw("$effDateExpr >= ?", [$from->format('Y-m-d H:i:s')]);
        }
        if ($to) {
            $q->whereRaw("$effDateExpr <= ?", [$to->format('Y-m-d H:i:s')]);
        }
        if ($branchId) {
            $q->where('je.branch_id', $branchId);
        }

        $rows = $q->selectRaw("
                a.id   as account_id,
                a.code as account_code,
                a.name as account_name,
                t.code as type_code,
                COALESCE(SUM(jp.debit), 0)  as debit_sum,
                COALESCE(SUM(jp.credit), 0) as credit_sum
            ")
            ->groupBy('a.id', 'a.code', 'a.name', 't.code')
            ->orderBy('t.code')
            ->orderBy('a.code')
            ->get();

        // ---------- Build sections ----------
        $sections = [
            'income'       => ['rows' => [], 'total' => 0.0],
            'cogs'         => ['rows' => [], 'total' => 0.0],
            'expenses'     => ['rows' => [], 'total' => 0.0],
            'gross_profit' => 0.0,
            'net_profit'   => 0.0,
        ];

        foreach ($rows as $r) {
            $typeCode = (string)$r->type_code;

            if (!isset($typeMapToSection[$typeCode])) {
                continue;
            }

            // Default section based on type
            $sectionKey = $typeMapToSection[$typeCode];

            // ---- Split EXPENSE into COGS vs operating expenses by account_code ----
            // COGS accounts in your chart:
            //   5100 = Cost of Goods Sold
            //   5205 = Purchase Price Variance  (you can move this to expenses if you prefer)
            if ($typeCode === 'EXPENSE') {
                if (in_array($r->account_code, ['5100', '5205'], true)) {
                    $sectionKey = 'cogs';
                } else {
                    $sectionKey = 'expenses';
                }
            }

            $debit  = (float)$r->debit_sum;
            $credit = (float)$r->credit_sum;
            $netDc  = $debit - $credit; // debit - credit

            // Sign logic:
            // - INCOME (credit-nature): P&L amount = credit - debit = -netDc
            // - EXPENSE/COGS (debit-nature): P&L amount = debit - credit = netDc
            switch ($typeCode) {
                case 'INCOME':
                    $amount = -$netDc; // positive when credit > debit
                    break;

                case 'EXPENSE':
                default:
                    $amount = $netDc; // positive when debit > credit
                    break;
            }

            $amount = round($amount, 2);

            $sections[$sectionKey]['rows'][] = [
                'account_id'   => (int)$r->account_id,
                'account_code' => $r->account_code,
                'account_name' => $r->account_name,
                'type_code'    => $typeCode,
                'amount'       => $amount,
            ];

            $sections[$sectionKey]['total'] += $amount;
        }

        // Round section totals
        foreach (['income', 'cogs', 'expenses'] as $key) {
            $sections[$key]['total'] = round($sections[$key]['total'], 2);
        }

        // ---------- Gross & Net Profit ----------
        $grossProfit = $sections['income']['total'] - $sections['cogs']['total'];
        $netProfit   = $sections['income']['total']
                     - $sections['cogs']['total']
                     - $sections['expenses']['total'];

        $sections['gross_profit'] = round($grossProfit, 2);
        $sections['net_profit']   = round($netProfit, 2);

        return [
            'from'      => $from?->toDateString(),
            'to'        => $to?->toDateString(),
            'branch_id' => $branchId,
            'sections'  => $sections,
        ];
    }
}
