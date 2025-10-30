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

        // Effective date expression for ordering and range
        $effDateExpr = "COALESCE(jp.created_at, je.entry_date, je.created_at)";

        // -----------------------------
        // 1) Opening balance (before from)
        // -----------------------------
        $opening = 0.0;
        if ($from) {
            $openingQ = DB::table('journal_postings as jp')
                ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
                ->whereIn('jp.party_type', $partyTypes);

            if ($partyId) $openingQ->where('jp.party_id', $partyId);
            if ($branchId) $openingQ->where('je.branch_id', $branchId);

            $openingQ->whereRaw("$effDateExpr < ?", [date_format($from, 'Y-m-d 00:00:00')]);

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
            ->whereIn('jp.party_type', $partyTypes);

        if ($partyId)  $baseQ->where('jp.party_id', $partyId);
        if ($branchId) $baseQ->where('je.branch_id', $branchId);
        if ($from)     $baseQ->whereRaw("$effDateExpr >= ?", [date_format($from, 'Y-m-d 00:00:00')]);
        if ($to)       $baseQ->whereRaw("$effDateExpr <= ?", [date_format($to, 'Y-m-d 23:59:59')]);

        $total = (clone $baseQ)->count();

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
                ->whereIn('jp.party_type', $partyTypes);

            if ($partyId)  $priorQ->where('jp.party_id', $partyId);
            if ($branchId) $priorQ->where('je.branch_id', $branchId);
            if ($from)     $priorQ->whereRaw("$effDateExpr >= ?", [date_format($from, 'Y-m-d 00:00:00')]);
            if ($to)       $priorQ->whereRaw("$effDateExpr <= ?", [date_format($to, 'Y-m-d 23:59:59')]);

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
            'last_page'         => (int)ceil($total / $perPage),
        ];
    }
}
