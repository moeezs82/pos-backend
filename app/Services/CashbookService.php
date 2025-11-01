<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use DateTimeImmutable;
use DateInterval;
use DatePeriod;

class CashbookService
{
    /**
     * Daily Cashbook Summary with Expense separated.
     *
     * Params (array $p):
     * - from?: 'Y-m-d'|Carbon (default: today - 6 days)
     * - to?:   'Y-m-d'|Carbon (default: today)
     * - branch_id?: int|null
     * - account_ids?: int[]         // override default cash/bank: 1000 (Cash in Hand), 1010 (Bank)
     * - include_bank?: bool         // default true; false = use only Cash (1000)
     * - page?: int                  // paginate days (optional)
     * - per_page?: int              // default 1000; max 5000
     *
     * Returns:
     * - opening: float
     * - rows[]: { date, receipts, payments, expense, net, closing }
     * - totals: { receipts, payments, expense, net, closing }
     * - filters, pagination?
     */
    public function dailySummary(array $p): array
    {
        // ----- Normalize input dates
        $today = new DateTimeImmutable(date('Y-m-d'));
        $from = isset($p['from']) && $p['from']
            ? new DateTimeImmutable(is_string($p['from']) ? $p['from'] : $p['from']->format('Y-m-d'))
            : $today->sub(new DateInterval('P6D'));
        $to = isset($p['to']) && $p['to']
            ? new DateTimeImmutable(is_string($p['to']) ? $p['to'] : $p['to']->format('Y-m-d'))
            : $today;

        if ($from > $to) {
            throw new InvalidArgumentException('`from` must be on or before `to`.');
        }

        $branchId    = isset($p['branch_id']) ? (int)$p['branch_id'] : null;
        $page        = isset($p['page']) ? max(1, (int)$p['page']) : null;
        $perPage     = isset($p['per_page']) ? max(1, min(5000, (int)$p['per_page'])) : 1000;
        $includeBank = array_key_exists('include_bank', $p) ? (bool)$p['include_bank'] : true;

        // ----- Cashbook accounts (from your seeder: 1000 Cash in Hand, 1010 Bank)
        $accountIds = isset($p['account_ids']) ? array_values(array_filter((array)$p['account_ids'])) : [];
        if (empty($accountIds)) {
            $accQ = DB::table('accounts')->select('id')->whereIn('code', $includeBank ? ['1000','1010'] : ['1000']);
            $accountIds = $accQ->pluck('id')->map(fn($v) => (int)$v)->all();
        }
        if (empty($accountIds)) {
            throw new InvalidArgumentException('No cashbook accounts found (expected code 1000/1010). Provide `account_ids[]` or seed accounts.');
        }

        // ----- AccountType id for EXPENSE
        $expenseTypeId = (int) DB::table('account_types')->where('code', 'EXPENSE')->value('id');

        // ----- Effective date expression (align with your other modules)
        $effDateExpr = "COALESCE(jp.created_at, je.entry_date, je.created_at)";

        // ===========================
        // 1) Opening balance (before from)
        // ===========================
        $openingQ = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->whereIn('jp.account_id', $accountIds)
            ->whereRaw("$effDateExpr < ?", [$from->format('Y-m-d 00:00:00')]);

        if ($branchId) $openingQ->where('je.branch_id', $branchId);

        // For cash/bank (ASSET): debit increases, credit decreases
        $opening = (float) $openingQ
            ->selectRaw('COALESCE(SUM(jp.debit - jp.credit), 0) as bal')
            ->value('bal');

        // ===========================
        // 2) Per-entry conditional aggregates, then roll up per day
        // ===========================
        // For each journal entry in range, compute:
        //   cash_debit_total, cash_credit_total, expense_debit_total
        // Then expense_paid_via_cash = LEAST(cash_credit_total, expense_debit_total)
        $entriesQ = DB::table('journal_entries as je')
            ->join('journal_postings as jp', 'jp.journal_entry_id', '=', 'je.id')
            ->leftJoin('accounts as a', 'a.id', '=', 'jp.account_id')
            ->whereRaw("$effDateExpr >= ?", [$from->format('Y-m-d 00:00:00')])
            ->whereRaw("$effDateExpr <= ?", [$to->format('Y-m-d 23:59:59')]);

        if ($branchId) $entriesQ->where('je.branch_id', $branchId);

        // Restrict scan to postings that either hit cash/bank or are EXPENSE (to keep it efficient)
        $entriesQ->where(function ($q) use ($accountIds, $expenseTypeId) {
            $q->whereIn('jp.account_id', $accountIds)
              ->orWhere('a.account_type_id', '=', $expenseTypeId);
        });

        // Build IN list safely (numeric IDs only)
        $inList = implode(',', array_map('intval', $accountIds));

        $perEntry = $entriesQ
            ->selectRaw("
                DATE($effDateExpr) as d,
                je.id as jeid,
                SUM(CASE WHEN jp.account_id IN ($inList) THEN jp.debit  ELSE 0 END) as cash_debit_total,
                SUM(CASE WHEN jp.account_id IN ($inList) THEN jp.credit ELSE 0 END) as cash_credit_total,
                SUM(CASE WHEN a.account_type_id = ?      THEN jp.debit  ELSE 0 END) as expense_debit_total
            ", [$expenseTypeId])
            ->groupBy('d','jeid')
            ->orderBy('d','asc')
            ->get();

        // Roll up per day with expense_paid_via_cash = LEAST(cash_credit_total, expense_debit_total)
        $perDay = [];
        foreach ($perEntry as $r) {
            $d = $r->d;
            if (!isset($perDay[$d])) {
                $perDay[$d] = (object)[
                    'receipts' => 0.0,
                    'payments' => 0.0,
                    'expense'  => 0.0,
                ];
            }
            $receipts = (float)$r->cash_debit_total;
            $payments = (float)$r->cash_credit_total;
            $expDbt   = (float)$r->expense_debit_total;
            $expPaid  = min($payments, $expDbt);

            $perDay[$d]->receipts += $receipts;
            $perDay[$d]->payments += $payments;
            $perDay[$d]->expense  += $expPaid;
        }

        // ===========================
        // 3) Dense day series + running closing
        // ===========================
        $period = new DatePeriod($from, new DateInterval('P1D'), $to->add(new DateInterval('P1D')));

        $running = $opening;
        $rows = [];
        $totalsReceipts = 0.0;
        $totalsPayments = 0.0;
        $totalsExpense  = 0.0;

        foreach ($period as $day) {
            $key = $day->format('Y-m-d');
            $dReceipts = isset($perDay[$key]) ? (float)$perDay[$key]->receipts : 0.0;
            $dPayments = isset($perDay[$key]) ? (float)$perDay[$key]->payments : 0.0;
            $dExpense  = isset($perDay[$key]) ? (float)$perDay[$key]->expense  : 0.0;

            $totalsReceipts += $dReceipts;
            $totalsPayments += $dPayments;
            $totalsExpense  += $dExpense;

            $net = $dReceipts - $dPayments; // Net cash movement (unchanged definition)
            $running += $net;

            $rows[] = [
                'date'     => $key,
                'receipts' => round($dReceipts, 2),
                'payments' => round($dPayments, 2),
                'expense'  => round($dExpense, 2), // paid via cash/bank
                'net'      => round($net, 2),
                'closing'  => round($running, 2),
            ];
        }

        // Optional day pagination
        $totalDays  = count($rows);
        $pagination = null;
        if ($page !== null) {
            $lastPage = (int)ceil($totalDays / $perPage);
            $offset   = ($page - 1) * $perPage;
            $rows     = array_slice($rows, $offset, $perPage);
            $pagination = [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total_days'   => $totalDays,
                'last_page'    => $lastPage,
            ];
        }

        return [
            'opening' => round($opening, 2),
            'rows'    => $rows,
            'totals'  => [
                'receipts' => round($totalsReceipts, 2),
                'payments' => round($totalsPayments, 2),
                'expense'  => round($totalsExpense, 2),
                'net'      => round($totalsReceipts - $totalsPayments, 2),
                'closing'  => round($running, 2),
            ],
            'filters' => [
                'from'         => $from->format('Y-m-d'),
                'to'           => $to->format('Y-m-d'),
                'branch_id'    => $branchId,
                'account_ids'  => $accountIds,
                'include_bank' => $includeBank,
            ],
            'pagination' => $pagination,
        ];
    }
}
