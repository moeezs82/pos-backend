<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use App\Models\Customer;
use App\Models\Vendor;

class LedgerService
{
    /**
     * Build a party-agnostic ledger with optional customer_id/vendor_id,
     * date range, branch filter and pagination.
     *
     * Params (array $p):
     * - customer_id?: int
     * - vendor_id?: int
     * - party_type?: 'customer'|'vendor'  // used when no *_id given (defaults to 'customer')
     * - party_id?: 'customer'|'vendor' 
     * - branch_id?: int
     * - from?: \Illuminate\Support\Carbon|string 'Y-m-d'
     * - to?:   \Illuminate\Support\Carbon|string 'Y-m-d'
     * - page?: int (default 1)
     * - per_page?: int (default 15, max 100)
     *
     * Returns array:
     * - party_type, party_id, opening, opening_for_page, items[], total, per_page, current_page, last_page
     */
    public function getLedger(array $p): array
    {
        // -----------------------------
        // Normalize + defaults
        // -----------------------------
        $page     = max(1, (int)($p['page'] ?? 1));
        $perPage  = max(1, min(100, (int)($p['per_page'] ?? 15)));
        $branchId = isset($p['branch_id']) ? (int)$p['branch_id'] : null;

        $from = isset($p['from']) && $p['from']
            ? (is_string($p['from']) ? date_create($p['from']) : $p['from'])
            : null;

        $to = isset($p['to']) && $p['to']
            ? (is_string($p['to']) ? date_create($p['to']) : $p['to'])
            : null;

        $customerId = isset($p['customer_id']) ? (int)$p['customer_id'] : null;
        $vendorId   = isset($p['vendor_id'])   ? (int)$p['vendor_id']   : null;

        if ($customerId && $vendorId) {
            throw new InvalidArgumentException('Provide either customer_id or vendor_id, not both.');
        }

        // Decide party_type + id
        if ($customerId) {
            $partyType = 'customer';
            $partyId   = $customerId;
        } elseif ($vendorId) {
            $partyType = 'vendor';
            $partyId   = $vendorId;
        } else {
            // When no specific party is chosen, allow aggregate-by-type.
            $partyType = in_array(($p['party_type'] ?? 'customer'), ['customer','vendor'], true)
                ? $p['party_type']
                : 'customer';
            $partyId = null; // aggregate across all parties of this type
        }
        if (isset($p['party_id'])) {
            $partyId = $p['party_id'] ?: null;
        }

        $partyClass = $partyType === 'vendor' ? Vendor::class : Customer::class;
        $partyTypes = [$partyType, $partyClass];

        // Trade ledgers are derived ONLY from the commercial control account:
        // AR (1200) for customers, AP (2000) for vendors. A party tag on any
        // other account (loans 1300, Qameti 1310, expenses 5300, …) must NOT
        // contaminate the customer/vendor commercial balance.
        $controlCodes = $partyType === 'vendor' ? ['2000'] : ['1200'];
        $controlAccountIds = DB::table('accounts')->whereIn('code', $controlCodes)->pluck('id')->all();
        if (empty($controlAccountIds)) {
            $controlAccountIds = [0]; // no control account => empty ledger, never leak
        }

        // Effective date expression for ordering and range
        $effDateExpr = "COALESCE(jp.created_at, je.entry_date, je.created_at)";

        // -----------------------------
        // 1) Opening balance (before from)
        // -----------------------------
        $opening = 0.0;
        if ($from) {
            $openingQ = DB::table('journal_postings as jp')
                ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
                ->whereIn('jp.party_type', $partyTypes)->whereIn('jp.account_id', $controlAccountIds);

            if ($partyId) $openingQ->where('jp.party_id', $partyId);
            if ($branchId) $openingQ->where('je.branch_id', $branchId);

            $openingQ->whereRaw("DATE($effDateExpr) < ?", [date_format($from, 'Y-m-d')]);

            $opening = (float) $openingQ
                ->selectRaw('COALESCE(SUM(jp.debit - jp.credit), 0) as bal')
                ->value('bal');
        }

        // -----------------------------
        // 2) Base filtered set
        // -----------------------------
        $baseQ = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->leftJoin('accounts as a', 'a.id', '=', 'jp.account_id')
            ->whereIn('jp.party_type', $partyTypes)->whereIn('jp.account_id', $controlAccountIds);

        if ($partyId)  $baseQ->where('jp.party_id', $partyId);
        if ($branchId) $baseQ->where('je.branch_id', $branchId);
        if ($from)     $baseQ->whereRaw("DATE($effDateExpr) >= ?", [date_format($from, 'Y-m-d')]);
        if ($to)       $baseQ->whereRaw("DATE($effDateExpr) <= ?", [date_format($to, 'Y-m-d')]);

        $total = (clone $baseQ)->count();

        // Resolve the latest page from THIS exact filtered query when the caller
        // asks to "follow latest" (page=last / latest=1), and always clamp an
        // out-of-range page down to the real last page instead of returning an
        // empty impossible page. Ordering stays ascending, so the last page
        // holds the newest postings.
        $lastPage = (int) max(1, (int) ceil($total / $perPage));
        $wantsLatest = !empty($p['latest'])
            || (isset($p['page']) && is_string($p['page']) && strtolower($p['page']) === 'last');
        if ($wantsLatest || $page > $lastPage) {
            $page = $lastPage;
        }

        $pageRows = (clone $baseQ)
            ->selectRaw("
                jp.id as posting_id,
                jp.journal_entry_id,
                $effDateExpr as eff_date,
                je.branch_id,
                je.memo,
                a.name as account_name,
                COALESCE(jp.debit, 0)  as debit,
                COALESCE(jp.credit, 0) as credit
            ")
            ->orderByRaw("$effDateExpr ASC")
            ->orderBy('jp.id', 'ASC')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        // -----------------------------
        // 3) priorDelta before this page (inside filters)
        // -----------------------------
        $openingForPage = $opening;
        if ($page > 1 && $pageRows->isNotEmpty()) {
            $first    = $pageRows->first();
            $firstDate = (string)$first->eff_date;
            $firstId   = (int)$first->posting_id;

            $priorQ = DB::table('journal_postings as jp')
                ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
                ->whereIn('jp.party_type', $partyTypes)->whereIn('jp.account_id', $controlAccountIds);

            if ($partyId)  $priorQ->where('jp.party_id', $partyId);
            if ($branchId) $priorQ->where('je.branch_id', $branchId);
            if ($from)     $priorQ->whereRaw("DATE($effDateExpr) >= ?", [date_format($from, 'Y-m-d')]);
            if ($to)       $priorQ->whereRaw("DATE($effDateExpr) <= ?", [date_format($to, 'Y-m-d')]);

            $priorQ->where(function ($q) use ($effDateExpr, $firstDate, $firstId) {
                $q->whereRaw("$effDateExpr < ?", [$firstDate])
                  ->orWhere(function ($q2) use ($effDateExpr, $firstDate, $firstId) {
                      $q2->whereRaw("$effDateExpr = ?", [$firstDate])
                         ->where('jp.id', '<', $firstId);
                  });
            });

            $priorDelta = (float) $priorQ
                ->selectRaw('COALESCE(SUM(jp.debit - jp.credit), 0) as bal')
                ->value('bal');

            $openingForPage += $priorDelta;
        }

        // -----------------------------
        // 4) Items + running balance
        // -----------------------------
        $running = $openingForPage;
        $items = $pageRows->map(function ($r) use (&$running) {
            $debit  = (float)$r->debit;
            $credit = (float)$r->credit;
            $running += ($debit - $credit);

            return [
                'posting_id'       => (int)$r->posting_id,
                'journal_entry_id' => (int)$r->journal_entry_id,
                'date'             => (string)$r->eff_date,
                'branch_id'        => (int)$r->branch_id,
                'account_name'     => $r->account_name,   // may be null
                'memo'             => $r->memo,
                'debit'            => $debit,
                'credit'           => $credit,
                'balance'          => round($running, 2),
            ];
        });

        return [
            'party_type'        => $partyType,                 // 'customer' or 'vendor'
            'party_id'          => $partyId,                   // null => aggregated by party_type
            'opening'           => round($opening, 2),
            'opening_for_page'  => round($openingForPage, 2),
            'items'             => $items,
            'total'             => $total,
            'per_page'          => $perPage,
            'current_page'      => $page,
            'last_page'         => $lastPage,
        ];
    }
}
