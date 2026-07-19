<?php

namespace App\Services\Reports;

use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Read-only Loan / Qameti / Expense subledgers, derived from the double-entry
 * journal (the money truth), branch-scoped, with opening/closing and GL
 * reconciliation. These change classification/presentation only — they never
 * mutate journals.
 *
 * Sign conventions:
 *   Loans (asset 1300):  Dr = given (+outstanding), Cr = recovered (−). closing = opening + given − recovered.
 *   Qameti (asset 1310): Dr = payment (+),          Cr = collection (−).  closing = opening + payments − collections.
 *   Expenses:            Dr = expense activity, Cr = reversal/correction. net = debits − credits.
 */
class SubledgerService
{
    private const EFF = 'COALESCE(je.entry_date, je.created_at, jp.created_at)';

    private function accountId(string $code): ?int
    {
        $id = DB::table('accounts')->where('code', $code)->value('id');
        return $id ? (int) $id : null;
    }

    /** GL balance of an account up to (and including) $asOf, branch-scoped. */
    private function glBalance(int $accountId, ?int $branchId, ?string $asOf): float
    {
        $q = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->where('jp.account_id', $accountId)
            ->when($branchId, fn ($qq) => $qq->where('je.branch_id', $branchId));
        if ($asOf) {
            $q->whereRaw('DATE(' . self::EFF . ') <= ?', [$asOf]);
        }
        return (float) $q->selectRaw('COALESCE(SUM(jp.debit - jp.credit),0) as bal')->value('bal');
    }

    // ── Loans (account 1300), grouped by borrower ──────────────────────────
    public function loans(array $p): array
    {
        $branchId = $p['branch_id'] ?? null;
        $from = $p['from'] ?? null;
        $to   = $p['to'] ?? null;
        $search = trim((string) ($p['search'] ?? ''));

        $acctId = $this->accountId('1300');
        if (!$acctId) {
            return ['summary' => $this->emptySummary(), 'borrowers' => [], 'reconciliation' => ['gl_closing' => 0.0, 'subledger_closing' => 0.0, 'difference' => 0.0]];
        }

        $base = fn () => DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->where('jp.account_id', $acctId)
            ->whereNotNull('jp.party_id')
            ->when($branchId, fn ($q) => $q->where('je.branch_id', $branchId));

        // Opening (before from) per borrower.
        $opening = [];
        if ($from) {
            foreach ((clone $base())->whereRaw('DATE(' . self::EFF . ') < ?', [$from])
                ->groupBy('jp.party_type', 'jp.party_id')
                ->selectRaw('jp.party_type, jp.party_id, COALESCE(SUM(jp.debit - jp.credit),0) op')->get() as $r) {
                $opening[$r->party_type . '#' . $r->party_id] = (float) $r->op;
            }
        }

        $rangeQ = $base();
        if ($from) $rangeQ->whereRaw('DATE(' . self::EFF . ') >= ?', [$from]);
        if ($to)   $rangeQ->whereRaw('DATE(' . self::EFF . ') <= ?', [$to]);
        $rangeRows = $rangeQ->groupBy('jp.party_type', 'jp.party_id')
            ->selectRaw('jp.party_type, jp.party_id, COALESCE(SUM(jp.debit),0) given, COALESCE(SUM(jp.credit),0) recovered')
            ->get();

        $keys = collect($opening)->keys()
            ->merge($rangeRows->map(fn ($r) => $r->party_type . '#' . $r->party_id))
            ->unique();

        $names = $this->partyNames($keys);

        $borrowers = [];
        $sumOpen = $sumGiven = $sumRec = 0.0;
        foreach ($keys as $key) {
            [$type, $id] = explode('#', $key);
            $row = $rangeRows->firstWhere(fn ($r) => $r->party_type . '#' . $r->party_id === $key);
            $op = (float) ($opening[$key] ?? 0);
            $given = (float) ($row->given ?? 0);
            $rec = (float) ($row->recovered ?? 0);
            $outstanding = round($op + $given - $rec, 2);
            $name = $names[$key] ?? ('#' . $id);
            if ($search !== '' && stripos($name, $search) === false) continue;

            $sumOpen += $op; $sumGiven += $given; $sumRec += $rec;
            $borrowers[] = [
                'party_type'  => $this->shortType($type),
                'party_id'    => (int) $id,
                'name'        => $name,
                'opening'     => round($op, 2),
                'given'       => round($given, 2),
                'recovered'   => round($rec, 2),
                'outstanding' => $outstanding,
                'status'      => abs($outstanding) < 0.005 ? 'settled' : ($outstanding < 0 ? 'credit' : 'outstanding'),
            ];
        }
        usort($borrowers, fn ($a, $b) => $b['outstanding'] <=> $a['outstanding']);

        // Per-transaction list (carries the payment method for the row chip).
        $txQ = $base()
            ->leftJoin('cash_ledger_entries as cle', 'cle.journal_entry_id', '=', 'je.id');
        if ($from) $txQ->whereRaw('DATE(' . self::EFF . ') >= ?', [$from]);
        if ($to)   $txQ->whereRaw('DATE(' . self::EFF . ') <= ?', [$to]);
        $txns = [];
        foreach ($txQ->orderByRaw(self::EFF . ' DESC')->orderBy('jp.id', 'DESC')
            ->selectRaw(self::EFF . ' as eff_date')
            ->selectRaw('jp.id, jp.party_type, jp.party_id, jp.debit, jp.credit, cle.method, cle.status, je.memo')
            ->get() as $r) {
            $name = $names[$r->party_type . '#' . $r->party_id] ?? ('#' . $r->party_id);
            if ($search !== '' && stripos($name, $search) === false) continue;
            $txns[] = [
                'id' => 'jp_' . $r->id,
                'date' => substr((string) $r->eff_date, 0, 10),
                'name' => $name,
                'party_type' => $this->shortType($r->party_type),
                'given' => round((float) $r->debit, 2),
                'recovered' => round((float) $r->credit, 2),
                'amount' => round((float) $r->debit - (float) $r->credit, 2),
                'method' => $r->method,
                'status' => $r->status ?: 'posted',
            ];
        }

        $closing = round($sumOpen + $sumGiven - $sumRec, 2);
        $glClosing = round($this->glBalance($acctId, $branchId, $to), 2);

        return [
            'summary' => [
                'opening' => round($sumOpen, 2),
                'given'   => round($sumGiven, 2),
                'recovered' => round($sumRec, 2),
                'closing' => $closing,
                'borrowers_outstanding' => count(array_filter($borrowers, fn ($b) => $b['status'] === 'outstanding')),
            ],
            'borrowers' => $borrowers,
            'transactions' => $txns,
            'reconciliation' => [
                'gl_closing' => $glClosing,
                'subledger_closing' => $closing,
                'difference' => round($glClosing - $closing, 2),
                'note' => 'Aggregated by borrower (no per-loan contract tracking yet).',
            ],
        ];
    }

    // ── Qameti (account 1310) ──────────────────────────────────────────────
    public function qameti(array $p): array
    {
        $branchId = $p['branch_id'] ?? null;
        $from = $p['from'] ?? null;
        $to   = $p['to'] ?? null;
        $search = trim((string) ($p['search'] ?? ''));

        $acctId = $this->accountId('1310');
        if (!$acctId) {
            return ['summary' => $this->emptySummary(), 'transactions' => [], 'reconciliation' => ['gl_closing' => 0.0, 'subledger_closing' => 0.0, 'difference' => 0.0]];
        }

        $opening = $from ? $this->glBalance($acctId, $branchId, date('Y-m-d', strtotime($from . ' -1 day'))) : 0.0;

        $rangeQ = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->leftJoin('cash_ledger_entries as cle', 'cle.journal_entry_id', '=', 'je.id')
            ->where('jp.account_id', $acctId)
            ->when($branchId, fn ($q) => $q->where('je.branch_id', $branchId));
        if ($from) $rangeQ->whereRaw('DATE(' . self::EFF . ') >= ?', [$from]);
        if ($to)   $rangeQ->whereRaw('DATE(' . self::EFF . ') <= ?', [$to]);

        $rows = (clone $rangeQ)
            ->orderByRaw(self::EFF . ' ASC')->orderBy('jp.id', 'ASC')
            ->selectRaw(self::EFF . ' as eff_date')
            ->selectRaw('jp.id, jp.debit, jp.credit, je.memo, cle.reference_name, cle.status, cle.method')
            ->get();

        $payments = $collections = 0.0;
        $running = $opening;
        $transactions = [];
        foreach ($rows as $r) {
            $payments += (float) $r->debit;
            $collections += (float) $r->credit;
            $running = round($running + (float) $r->debit - (float) $r->credit, 2);
            $transactions[] = [
                'id' => 'jp_' . $r->id,
                'date' => substr((string) $r->eff_date, 0, 10),
                'reference' => $r->reference_name ?: $r->memo,
                'payment' => round((float) $r->debit, 2),
                'collection' => round((float) $r->credit, 2),
                'balance' => $running,
                'method' => $r->method,
                'status' => $r->status ?: 'posted',
            ];
        }

        $closing = round($opening + $payments - $collections, 2);
        $glClosing = round($this->glBalance($acctId, $branchId, $to), 2);

        // Search narrows the visible list only; summary/reconciliation stay
        // period-wide (running balance already computed above).
        if ($search !== '') {
            $transactions = array_values(array_filter(
                $transactions,
                fn ($t) => stripos((string) ($t['reference'] ?? ''), $search) !== false
                    || stripos((string) ($t['method'] ?? ''), $search) !== false
            ));
        }

        return [
            'summary' => [
                'opening' => round($opening, 2),
                'payments' => round($payments, 2),
                'collections' => round($collections, 2),
                'closing' => $closing,
            ],
            'transactions' => $transactions,
            'reconciliation' => [
                'gl_closing' => $glClosing,
                'subledger_closing' => $closing,
                'difference' => round($glClosing - $closing, 2),
                'note' => 'Grouped by free-text reference (not a scheme entity).',
            ],
        ];
    }

    // ── Expenses (EXPENSE-type accounts) ───────────────────────────────────
    public function expenses(array $p): array
    {
        $branchId = $p['branch_id'] ?? null;
        $from = $p['from'] ?? null;
        $to   = $p['to'] ?? null;
        $search = trim((string) ($p['search'] ?? ''));

        // "Other expense" only — exclude system/inventory-driven accounts
        // (COGS 5100, PPV 5205). Single source of truth in config/pos.php so the
        // subledger, the expense-options endpoint and the submit guard agree.
        $excludedCodes = (array) config('pos.system_expense_codes', ['5100', '5205']);
        $expenseAccountIds = DB::table('accounts as a')
            ->join('account_types as t', 't.id', '=', 'a.account_type_id')
            ->where('t.code', 'EXPENSE')
            ->whereNotIn('a.code', $excludedCodes)
            ->pluck('a.id')->map(fn ($i) => (int) $i)->all();
        if (empty($expenseAccountIds)) {
            return ['summary' => ['total' => 0.0, 'reversals' => 0.0, 'net' => 0.0, 'count' => 0], 'by_account' => [], 'transactions' => []];
        }

        $rangeQ = fn () => DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->leftJoin('accounts as a', 'a.id', '=', 'jp.account_id')
            ->leftJoin('cash_ledger_entries as cle', 'cle.journal_entry_id', '=', 'je.id')
            ->whereIn('jp.account_id', $expenseAccountIds)
            ->when($branchId, fn ($q) => $q->where('je.branch_id', $branchId))
            ->when($from, fn ($q) => $q->whereRaw('DATE(' . self::EFF . ') >= ?', [$from]))
            ->when($to, fn ($q) => $q->whereRaw('DATE(' . self::EFF . ') <= ?', [$to]));

        // By account.
        $byAccount = [];
        $total = $reversals = 0.0;
        foreach ((clone $rangeQ())->groupBy('a.code', 'a.name')
            ->selectRaw('a.code, a.name, COALESCE(SUM(jp.debit),0) dr, COALESCE(SUM(jp.credit),0) cr')->get() as $r) {
            $dr = (float) $r->dr; $cr = (float) $r->cr;
            $total += $dr; $reversals += $cr;
            $byAccount[] = [
                'code' => $r->code, 'name' => $r->name,
                'expense' => round($dr, 2), 'reversal' => round($cr, 2), 'net' => round($dr - $cr, 2),
            ];
        }
        usort($byAccount, fn ($a, $b) => $b['net'] <=> $a['net']);

        // Transaction list (search applied in-query so it isn't limited to the page).
        $rows = (clone $rangeQ())
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('a.name', 'like', $like)
                        ->orWhere('a.code', 'like', $like)
                        ->orWhere('cle.reference_name', 'like', $like)
                        ->orWhere('je.memo', 'like', $like)
                        ->orWhere('cle.method', 'like', $like);
                });
            })
            ->orderByRaw(self::EFF . ' DESC')->orderBy('jp.id', 'DESC')
            ->limit((int) ($p['per_page'] ?? 100))
            ->selectRaw(self::EFF . ' as eff_date')
            ->selectRaw('jp.id, jp.debit, jp.credit, a.code, a.name as account_name, je.memo, cle.reference_name, cle.status, cle.method')
            ->get();

        $transactions = $rows->map(fn ($r) => [
            'id' => 'jp_' . $r->id,
            'date' => substr((string) $r->eff_date, 0, 10),
            'account_code' => $r->code,
            'account_name' => $r->account_name,
            'amount' => round((float) $r->debit - (float) $r->credit, 2),
            'method' => $r->method,
            'payee' => $r->reference_name ?: $r->memo,
            'status' => $r->status ?: 'posted',
        ])->all();

        return [
            'summary' => [
                'total' => round($total, 2),
                'reversals' => round($reversals, 2),
                'net' => round($total - $reversals, 2),
                'count' => count($transactions),
            ],
            'by_account' => $byAccount,
            'transactions' => $transactions,
        ];
    }

    // ── helpers ─────────────────────────────────────────────────────────────
    private function emptySummary(): array
    {
        return ['opening' => 0.0, 'given' => 0.0, 'recovered' => 0.0, 'closing' => 0.0];
    }

    private function shortType(string $fqcnOrShort): string
    {
        $s = strtolower($fqcnOrShort);
        if (str_contains($s, 'vendor')) return 'vendor';
        if (str_contains($s, 'user')) return 'user';
        if (str_contains($s, 'customer')) return 'customer';
        return $s;
    }

    /** @param \Illuminate\Support\Collection $keys "Type#id" */
    private function partyNames($keys): array
    {
        $byType = ['customer' => [], 'vendor' => [], 'user' => []];
        foreach ($keys as $k) {
            [$type, $id] = explode('#', $k);
            $short = $this->shortType($type);
            if (isset($byType[$short])) $byType[$short][] = (int) $id;
        }
        $out = [];
        $map = ['customer' => Customer::class, 'vendor' => Vendor::class, 'user' => User::class];
        foreach ($byType as $short => $ids) {
            if (empty($ids)) continue;
            $rows = $map[$short]::query()->whereIn('id', array_unique($ids))->get();
            foreach ($rows as $m) {
                $name = $short === 'user'
                    ? ($m->name ?? ('#' . $m->id))
                    : (trim(($m->first_name ?? '') . ' ' . ($m->last_name ?? '')) ?: ('#' . $m->id));

                // Key back to both FQCN and short forms.
                $out[$map[$short] . '#' . $m->id] = $name;
                $out[$short . '#' . $m->id] = $name;
            }
        }
        return $out;
    }
}
