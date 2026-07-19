<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative, account-aware party balance calculations.
 *
 * This is the single source of truth for how a Customer/Vendor commercial
 * (trade) balance and a borrower's Loan balance are derived from the
 * double-entry journal. Every list/detail/summary/payment surface must use
 * this service so the same party can never show two contradictory balances.
 *
 * Classification rules (never mix these account classes in one number):
 *   Customer trade  = Accounts Receivable control account (AR_CODE) ONLY.
 *                     trade_balance = AR debits - AR credits (>0 = owes us).
 *   Vendor trade    = Accounts Payable control account (AP_CODE) ONLY.
 *                     trade_balance = AP credits - AP debits (>0 = we owe).
 *   Loan            = Loans Receivable control account (LOAN_CODE) ONLY.
 *                     loan_balance = given (Dr) - recovered (Cr).
 *
 * A loan/Qameti/expense posting tagged with a customer or vendor party must
 * never inflate their trade balance, and vice-versa.
 */
class PartyBalanceService
{
    /** Chart-of-accounts control codes (centralized here, not scattered). */
    public const AR_CODE   = '1200';
    public const AP_CODE   = '2000';
    public const LOAN_CODE = '1300';

    private const EFF = 'COALESCE(jp.created_at, je.entry_date, je.created_at)';

    /** All account ids carrying the given control code (branches may repeat codes). */
    private function accountIds(string $code): array
    {
        $ids = DB::table('accounts')->where('code', $code)->pluck('id')->map(fn ($i) => (int) $i)->all();
        return $ids ?: [0]; // never leak: [0] matches nothing
    }

    /** Accept both the short alias and the model FQCN stored in party_type. */
    private function partyTypes(string $short): array
    {
        return match ($short) {
            'vendor'   => ['vendor', Vendor::class],
            'user'     => ['user', User::class],
            default    => ['customer', Customer::class],
        };
    }

    // ── Customer trade (AR only) ───────────────────────────────────────────
    public function customerTrade(int $customerId, ?int $branchId): array
    {
        $row = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->whereIn('jp.party_type', $this->partyTypes('customer'))
            ->where('jp.party_id', $customerId)
            ->whereIn('jp.account_id', $this->accountIds(self::AR_CODE))
            ->when($branchId, fn ($q) => $q->where('je.branch_id', $branchId))
            ->selectRaw('
                COALESCE(SUM(CASE WHEN jp.debit  > 0 THEN jp.debit  ELSE 0 END),0) AS trade_debit,
                COALESCE(SUM(CASE WHEN jp.credit > 0 THEN jp.credit ELSE 0 END),0) AS trade_credit
            ')->first();

        $debit  = (float) ($row->trade_debit ?? 0);
        $credit = (float) ($row->trade_credit ?? 0);

        return [
            'trade_debit'   => round($debit, 2),   // AR debits (sales/opening/adjustments)
            'trade_credit'  => round($credit, 2),  // AR credits (receipts/returns)
            'trade_balance' => round($debit - $credit, 2), // >0 = customer owes us
        ];
    }

    // ── Vendor trade (AP only) ─────────────────────────────────────────────
    public function vendorTrade(int $vendorId, ?int $branchId): array
    {
        $row = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->whereIn('jp.party_type', $this->partyTypes('vendor'))
            ->where('jp.party_id', $vendorId)
            ->whereIn('jp.account_id', $this->accountIds(self::AP_CODE))
            ->when($branchId, fn ($q) => $q->where('je.branch_id', $branchId))
            ->selectRaw('
                COALESCE(SUM(CASE WHEN jp.credit > 0 THEN jp.credit ELSE 0 END),0) AS trade_credit,
                COALESCE(SUM(CASE WHEN jp.debit  > 0 THEN jp.debit  ELSE 0 END),0) AS trade_debit
            ')->first();

        $credit = (float) ($row->trade_credit ?? 0); // purchases (increase AP)
        $debit  = (float) ($row->trade_debit ?? 0);  // payments (decrease AP)

        return [
            'trade_credit'  => round($credit, 2),  // purchases
            'trade_debit'   => round($debit, 2),   // payments
            'trade_balance' => round($credit - $debit, 2), // >0 = we owe vendor
        ];
    }

    // ── Loan summary (Loans Receivable 1300) for any borrower ──────────────
    public function loanSummary(string $partyShort, int $partyId, ?int $branchId): array
    {
        $row = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->whereIn('jp.party_type', $this->partyTypes($partyShort))
            ->where('jp.party_id', $partyId)
            ->whereIn('jp.account_id', $this->accountIds(self::LOAN_CODE))
            ->when($branchId, fn ($q) => $q->where('je.branch_id', $branchId))
            ->selectRaw('
                COALESCE(SUM(jp.debit),0)  AS given,
                COALESCE(SUM(jp.credit),0) AS recovered
            ')->first();

        $given     = (float) ($row->given ?? 0);
        $recovered = (float) ($row->recovered ?? 0);
        $balance   = round($given - $recovered, 2);

        return [
            'loan_given'     => round($given, 2),
            'loan_recovered' => round($recovered, 2),
            'loan_balance'   => $balance, // >0 = borrower owes; <0 = over-recovered/review
            'has_activity'   => ($given > 0 || $recovered > 0),
            'status'         => abs($balance) < 0.005
                ? 'settled'
                : ($balance < 0 ? 'credit_review' : 'outstanding'),
        ];
    }

    // ── Per-party Loan Ledger (paginated, running balance) ─────────────────
    /**
     * $p: party_type (customer|vendor|user), party_id, branch_id?, from?, to?,
     *     page?, per_page?
     */
    public function loanLedger(array $p): array
    {
        $partyShort = in_array(($p['party_type'] ?? 'customer'), ['customer', 'vendor', 'user'], true)
            ? $p['party_type'] : 'customer';
        $partyId  = (int) ($p['party_id'] ?? 0);
        $branchId = isset($p['branch_id']) ? (int) $p['branch_id'] : null;
        $page     = max(1, (int) ($p['page'] ?? 1));
        $perPage  = max(1, min(100, (int) ($p['per_page'] ?? 15)));
        $from     = !empty($p['from']) ? (is_string($p['from']) ? $p['from'] : date_format($p['from'], 'Y-m-d')) : null;
        $to       = !empty($p['to'])   ? (is_string($p['to'])   ? $p['to']   : date_format($p['to'], 'Y-m-d'))   : null;

        $accountIds = $this->accountIds(self::LOAN_CODE);
        $types      = $this->partyTypes($partyShort);
        $eff        = self::EFF;

        $base = fn () => DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->whereIn('jp.party_type', $types)
            ->where('jp.party_id', $partyId)
            ->whereIn('jp.account_id', $accountIds)
            ->when($branchId, fn ($q) => $q->where('je.branch_id', $branchId));

        // Opening before `from`.
        $opening = 0.0;
        if ($from) {
            $opening = (float) (clone $base())
                ->whereRaw("DATE($eff) < ?", [$from])
                ->selectRaw('COALESCE(SUM(jp.debit - jp.credit),0) as bal')->value('bal');
        }

        $rangeQ = $base();
        if ($from) $rangeQ->whereRaw("DATE($eff) >= ?", [$from]);
        if ($to)   $rangeQ->whereRaw("DATE($eff) <= ?", [$to]);

        $total = (clone $rangeQ)->count();

        // Follow-latest + out-of-range clamp against this exact filtered query
        // (ascending order => last page holds the newest loan postings).
        $lastPage = (int) max(1, (int) ceil(($total ?: 0) / $perPage));
        $wantsLatest = !empty($p['latest'])
            || (isset($p['page']) && is_string($p['page']) && strtolower($p['page']) === 'last');
        if ($wantsLatest || $page > $lastPage) {
            $page = $lastPage;
        }

        $rows = (clone $rangeQ)
            ->leftJoin('cash_ledger_entries as cle', 'cle.journal_entry_id', '=', 'je.id')
            ->selectRaw("
                jp.id as posting_id, jp.journal_entry_id,
                $eff as eff_date, je.branch_id, je.memo,
                COALESCE(jp.debit,0) as debit, COALESCE(jp.credit,0) as credit,
                cle.method, cle.reference_name, cle.status
            ")
            ->orderByRaw("$eff ASC")->orderBy('jp.id', 'ASC')
            ->skip(($page - 1) * $perPage)->take($perPage)->get();

        // Running balance seeded with opening + everything before this page.
        $openingForPage = $opening;
        if ($page > 1 && $rows->isNotEmpty()) {
            $first     = $rows->first();
            $firstDate = (string) $first->eff_date;
            $firstId   = (int) $first->posting_id;
            $priorQ = $base();
            if ($from) $priorQ->whereRaw("DATE($eff) >= ?", [$from]);
            if ($to)   $priorQ->whereRaw("DATE($eff) <= ?", [$to]);
            $priorQ->where(function ($q) use ($eff, $firstDate, $firstId) {
                $q->whereRaw("$eff < ?", [$firstDate])
                  ->orWhere(function ($q2) use ($eff, $firstDate, $firstId) {
                      $q2->whereRaw("$eff = ?", [$firstDate])->where('jp.id', '<', $firstId);
                  });
            });
            $openingForPage += (float) $priorQ
                ->selectRaw('COALESCE(SUM(jp.debit - jp.credit),0) as bal')->value('bal');
        }

        $running = $openingForPage;
        $items = $rows->map(function ($r) use (&$running) {
            $debit  = (float) $r->debit;
            $credit = (float) $r->credit;
            $running += ($debit - $credit);
            return [
                'posting_id'       => (int) $r->posting_id,
                'journal_entry_id' => (int) $r->journal_entry_id,
                'date'             => substr((string) $r->eff_date, 0, 10),
                'branch_id'        => (int) $r->branch_id,
                'given'            => round($debit, 2),
                'recovered'        => round($credit, 2),
                'balance'          => round($running, 2),
                'method'           => $r->method,
                'reference'        => $r->reference_name ?: $r->memo,
                'status'           => $r->status ?: 'posted',
            ];
        });

        $summary = $this->loanSummary($partyShort, $partyId, $branchId);

        return [
            'party_type'       => $partyShort,
            'party_id'         => $partyId,
            'opening'          => round($opening, 2),
            'opening_for_page' => round($openingForPage, 2),
            'summary'          => $summary,
            'items'            => $items,
            'total'            => $total,
            'per_page'         => $perPage,
            'current_page'     => $page,
            'last_page'        => (int) ceil(($total ?: 0) / $perPage),
        ];
    }
}
