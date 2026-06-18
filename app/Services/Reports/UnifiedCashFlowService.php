<?php

namespace App\Services\Reports;

use App\Enums\CashLedgerCategory;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Single, cohesive cash-flow view across EVERY source of cash movement.
 *
 * It reads the double-entry journal (the canonical money truth), filtered to the
 * cash/bank accounts (1000 / 1010). Because customer payments (Dr cash / Cr AR),
 * vendor payments (Dr AP / Cr cash), expenses, sales-related cash AND the new
 * non-sales categories all post there, this is the ONE place that sees them all.
 *
 *   IN  : customer_receipts, qameti_collections, loan_recoveries, claim_receipts, other_in
 *   OUT : vendor_payments, business_expenses, qameti_payments, loans_given, refunds_out, other_out
 */
class UnifiedCashFlowService
{
    private const CASH_CODES = ['1000', '1010'];
    private const EFF_DATE   = "COALESCE(je.entry_date, je.created_at, jp.created_at)";

    /** Human labels per bucket, used by both summary and the transaction feed. */
    public const BUCKET_LABELS = [
        'customer_receipts'  => 'Payment Received',
        'qameti_collections' => 'Qameti Collection',
        'loan_recoveries'    => 'Loan Recovered',
        'claim_receipts'     => 'Claim Receipt',
        'other_in'           => 'Other Cash In',
        'vendor_payments'    => 'Payment Sent',
        'business_expenses'  => 'Expense',
        'qameti_payments'    => 'Qameti Payment',
        'loans_given'        => 'Loan Given',
        'refunds_out'        => 'Refund Paid',
        'other_out'          => 'Other Cash Out',
    ];

    // =================================================================
    // SUMMARY  (opening / per-bucket totals / net / closing)
    // =================================================================

    /**
     * @param array{from?:string|null,to?:string|null,branch_id?:int|null,detailed?:bool} $p
     */
    public function build(array $p): array
    {
        [$from, $to, $branchId] = $this->range($p);
        $cashAccountIds = $this->cashAccountIds();

        $opening = $this->openingBalance($cashAccountIds, $branchId, $from);
        $rows    = $this->cashMovements($cashAccountIds, $branchId, $from, $to)->get();

        $incoming = ['customer_receipts' => 0.0, 'qameti_collections' => 0.0, 'loan_recoveries' => 0.0, 'claim_receipts' => 0.0, 'other_in' => 0.0];
        $outgoing = ['vendor_payments' => 0.0, 'business_expenses' => 0.0, 'qameti_payments' => 0.0, 'loans_given' => 0.0, 'refunds_out' => 0.0, 'other_out' => 0.0];

        foreach ($rows as $r) {
            $isIn   = ((float) $r->debit) > 0;
            $amount = round($isIn ? (float) $r->debit : (float) $r->credit, 2);
            [$bucket] = $this->classify($r, $isIn);
            if ($isIn) {
                $incoming[$bucket] = round(($incoming[$bucket] ?? 0) + $amount, 2);
            } else {
                $outgoing[$bucket] = round(($outgoing[$bucket] ?? 0) + $amount, 2);
            }
        }

        $totalIn  = round(array_sum($incoming), 2);
        $totalOut = round(array_sum($outgoing), 2);

        return [
            'filters' => ['from' => $from, 'to' => $to, 'branch_id' => $branchId],
            'summary' => [
                'opening'      => round($opening, 2),
                'incoming'     => $incoming + ['total' => $totalIn],
                'outgoing'     => $outgoing + ['total' => $totalOut],
                'net_movement' => round($totalIn - $totalOut, 2),
                'closing'      => round($opening + $totalIn - $totalOut, 2),
            ],
        ];
    }

    // =================================================================
    // TRANSACTIONS  (paginated unified ledger feed)
    // =================================================================

    /**
     * Paginated list of EVERY cash movement, newest first, each row labelled by
     * source (Payment Received / Sent / Expense / Qameti / Loan / ...), with the
     * counterparty name resolved and a void flag for rows owned by this module.
     *
     * @param array{
     *   from?:string|null,to?:string|null,branch_id?:int|null,
     *   direction?:string|null,   // in|out|all
     *   kind?:string|null,        // all|module|received|sent|expense
     *   category?:string|null,    // one of the 5 module categories
     *   search?:string|null,
     *   page?:int, per_page?:int
     * } $p
     */
    public function transactions(array $p): array
    {
        [$from, $to, $branchId] = $this->range($p);
        $cashAccountIds = $this->cashAccountIds();

        $perPage = max(1, min(100, (int) ($p['per_page'] ?? 20)));
        $page    = max(1, (int) ($p['page'] ?? 1));

        $base = $this->cashMovements($cashAccountIds, $branchId, $from, $to);

        // ---- filters --------------------------------------------------
        $direction = $p['direction'] ?? 'all';
        if ($direction === 'in')  $base->where('jp.debit', '>', 0);
        if ($direction === 'out') $base->where('jp.credit', '>', 0);

        $kind = $p['kind'] ?? 'all';
        if ($kind === 'module')   $base->whereNotNull('cle.id');
        if ($kind === 'received') $base->where('jp.debit', '>', 0);
        if ($kind === 'sent')     $base->where('jp.credit', '>', 0);
        if ($kind === 'expense')  $base->where('c.has_expense', 1);

        if (!empty($p['category'])) $base->where('cle.category', $p['category']);

        if (!empty($p['search'])) {
            $s = '%' . $p['search'] . '%';
            $base->where(function ($q) use ($s) {
                $q->where('je.memo', 'like', $s)
                  ->orWhere('cle.reference_name', 'like', $s);
            });
        }

        // ---- count + page --------------------------------------------
        $total = (clone $base)->count('jp.id');

        $rows = $base
            ->orderByRaw(self::EFF_DATE . ' DESC')
            ->orderBy('jp.id', 'DESC')
            ->forPage($page, $perPage)
            ->get();

        // ---- resolve counterparty names ------------------------------
        $jeIds = $rows->pluck('journal_entry_id')->filter()->unique()->all();
        $partyLeg = $this->partyLegFor($jeIds);          // je_id => [type,id]
        $names    = $this->resolveNames($rows, $partyLeg); // "Type#id" => name

        $items = $rows->map(function ($r) use ($partyLeg, $names) {
            $isIn   = ((float) $r->debit) > 0;
            $amount = round($isIn ? (float) $r->debit : (float) $r->credit, 2);
            [$bucket, $label] = $this->classify($r, $isIn);

            // counterparty: module row's own party, else the JE's party leg
            $pType = $r->cle_party_type ?: ($partyLeg[$r->journal_entry_id]['type'] ?? null);
            $pId   = $r->cle_party_id   ?: ($partyLeg[$r->journal_entry_id]['id'] ?? null);
            $party = $pType && $pId ? ($names[$pType . '#' . $pId] ?? null) : null;
            if (!$party) {
                $party = $r->cle_reference_name ?: null;
            }

            $cleId  = $r->cle_id ? (int) $r->cle_id : null;
            $status = $r->cle_status ?: 'posted';

            return [
                'id'             => 'jp_' . $r->id,             // stable row key
                'date'           => substr((string) $r->eff_date, 0, 10),
                'direction'      => $isIn ? 'in' : 'out',
                'amount'         => $amount,
                'bucket'         => $bucket,
                'label'          => $label,
                'source'         => $cleId ? 'module' : 'journal',
                'category'       => $r->cle_category,           // null unless module row
                'party'          => $party ?: 'Unlinked',
                'reference_name' => $r->cle_reference_name,
                'memo'           => $r->memo,
                'journal_entry_id' => (int) $r->journal_entry_id,
                'cash_ledger_entry_id' => $cleId,
                'status'         => $status,
                'can_void'       => $cleId !== null && $status === 'posted',
            ];
        })->all();

        return [
            'items'        => $items,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int) max(1, ceil($total / $perPage)),
            'filters'      => ['from' => $from, 'to' => $to, 'direction' => $direction, 'kind' => $kind],
        ];
    }

    // =================================================================
    // DAY BOOK  (day-by-day breakdown + single-day drill-down)
    //
    // Same source of truth as build()/transactions() above — every row here
    // is a cash-leg journal posting, labelled and party-resolved exactly the
    // same way the unified ledger feed is. A day book is just that feed,
    // grouped by day (summary) or filtered to one day (details).
    // =================================================================

    /**
     * Day-wise opening / in / out / net / closing, newest day first by default.
     *
     * @param array{from?:string|null,to?:string|null,branch_id?:int|null,page?:int,per_page?:int,order?:string} $p
     */
    public function dayBookSummary(array $p): array
    {
        [$from, $to, $branchId] = $this->range($p);
        $cashAccountIds = $this->cashAccountIds();

        $page    = max(1, (int) ($p['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($p['per_page'] ?? 30)));
        $order   = strtolower((string) ($p['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $opening = $this->openingBalance($cashAccountIds, $branchId, $from);

        $rows = $this->cashMovements($cashAccountIds, $branchId, $from, $to)->get();

        // Group postings by calendar day -> in/out totals.
        $byDate = [];
        foreach ($rows as $r) {
            $d = substr((string) $r->eff_date, 0, 10);
            $byDate[$d] ??= ['in' => 0.0, 'out' => 0.0, 'count' => 0];
            $isIn = ((float) $r->debit) > 0;
            $amount = round($isIn ? (float) $r->debit : (float) $r->credit, 2);
            if ($isIn) {
                $byDate[$d]['in'] = round($byDate[$d]['in'] + $amount, 2);
            } else {
                $byDate[$d]['out'] = round($byDate[$d]['out'] + $amount, 2);
            }
            $byDate[$d]['count']++;
        }

        // Walk every calendar day in range (ascending) so days with zero
        // activity still show up with a carried-forward balance.
        $daysAsc = [];
        $cursor  = new DateTimeImmutable($from);
        $end     = new DateTimeImmutable($to);
        $running = $opening;
        $totIn = $totOut = 0.0;

        while ($cursor <= $end) {
            $d   = $cursor->format('Y-m-d');
            $in  = $byDate[$d]['in']  ?? 0.0;
            $out = $byDate[$d]['out'] ?? 0.0;
            $net = round($in - $out, 2);

            $dayOpening = $running;
            $running    = round($running + $net, 2);

            $daysAsc[] = [
                'date'              => $d,
                'opening'           => $dayOpening,
                'in'                => $in,
                'out'               => $out,
                'net'               => $net,
                'closing'           => $running,
                'transaction_count' => $byDate[$d]['count'] ?? 0,
            ];

            $totIn  = round($totIn + $in, 2);
            $totOut = round($totOut + $out, 2);
            $cursor = $cursor->modify('+1 day');
        }

        $ordered   = $order === 'asc' ? $daysAsc : array_reverse($daysAsc);
        $totalDays = count($ordered);
        $lastPage  = max(1, (int) ceil($totalDays / $perPage));
        $current   = min($page, $lastPage);
        $pageDays  = array_slice($ordered, ($current - 1) * $perPage, $perPage);

        $pageIn = $pageOut = 0.0;
        foreach ($pageDays as $row) {
            $pageIn  = round($pageIn + $row['in'], 2);
            $pageOut = round($pageOut + $row['out'], 2);
        }

        return [
            'branch_id' => $branchId,
            'from'      => $from,
            'to'        => $to,
            'opening'   => round($opening, 2),
            'totals' => [
                'in'      => $totIn,
                'out'     => $totOut,
                'net'     => round($totIn - $totOut, 2),
                'closing' => $running,
            ],
            'page_totals' => [
                'in'  => $pageIn,
                'out' => $pageOut,
                'net' => round($pageIn - $pageOut, 2),
            ],
            'days' => $pageDays,
            'pagination' => [
                'total'        => $totalDays,
                'per_page'     => $perPage,
                'current_page' => $current,
                'last_page'    => $lastPage,
            ],
            'order' => $order,
        ];
    }

    /**
     * Every cash movement for ONE calendar day, fully labelled with party
     * names — i.e. the same row shape as transactions(), filtered to a day,
     * plus that day's opening/closing.
     *
     * @param array{date:string,branch_id?:int|null,direction?:string|null,kind?:string|null,search?:string|null,page?:int,per_page?:int} $p
     */
    public function dayBookDetails(array $p): array
    {
        $date = $p['date'];
        $branchId = isset($p['branch_id']) && $p['branch_id'] ? (int) $p['branch_id'] : null;
        $cashAccountIds = $this->cashAccountIds();

        $opening = $this->openingBalance($cashAccountIds, $branchId, $date);

        // Day totals computed over EVERY matching row for the day (not just
        // the current page), so totals/closing stay correct regardless of
        // pagination on the feed below.
        $totalsBase = $this->cashMovements($cashAccountIds, $branchId, $date, $date);
        if (($p['direction'] ?? 'all') === 'in')  $totalsBase->where('jp.debit', '>', 0);
        if (($p['direction'] ?? 'all') === 'out') $totalsBase->where('jp.credit', '>', 0);
        $totalsRow = DB::query()->fromSub($totalsBase, 'x')
            ->selectRaw('COALESCE(SUM(debit), 0) as t_in, COALESCE(SUM(credit), 0) as t_out')
            ->first();
        $totIn  = round((float) ($totalsRow->t_in ?? 0), 2);
        $totOut = round((float) ($totalsRow->t_out ?? 0), 2);
        $net     = round($totIn - $totOut, 2);
        $closing = round($opening + $net, 2);

        // Re-use the unified transactions() builder for the actual row feed
        // so the shape (labels, party names, void flags) is identical
        // everywhere in the app.
        $feed = $this->transactions([
            'from'      => $date,
            'to'        => $date,
            'branch_id' => $branchId,
            'direction' => $p['direction'] ?? 'all',
            'kind'      => $p['kind'] ?? 'all',
            'search'    => $p['search'] ?? null,
            'page'      => $p['page'] ?? 1,
            'per_page'  => $p['per_page'] ?? 50,
        ]);

        return [
            'date'       => $date,
            'branch_id'  => $branchId,
            'opening'    => round($opening, 2),
            'closing'    => $closing,
            'totals'     => ['in' => $totIn, 'out' => $totOut, 'net' => $net],
            'items'      => $feed['items'],
            'pagination' => [
                'total'        => $feed['total'],
                'per_page'     => $feed['per_page'],
                'current_page' => $feed['current_page'],
                'last_page'    => $feed['last_page'],
            ],
        ];
    }

    // =================================================================
    // shared internals
    // =================================================================

    private function range(array $p): array
    {
        $to   = $p['to']   ?? date('Y-m-d');
        $from = $p['from'] ?? (new DateTimeImmutable($to))->modify('-29 days')->format('Y-m-d');
        $branchId = isset($p['branch_id']) && $p['branch_id'] ? (int) $p['branch_id'] : null;
        return [$from, $to, $branchId];
    }

    private function cashAccountIds(): array
    {
        return DB::table('accounts')->whereIn('code', self::CASH_CODES)->pluck('id')->all();
    }

    private function openingBalance(array $cashAccountIds, ?int $branchId, string $from): float
    {
        return (float) DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->whereIn('jp.account_id', $cashAccountIds)
            ->when($branchId, fn ($q) => $q->where('je.branch_id', $branchId))
            ->whereRaw('DATE(' . self::EFF_DATE . ') < ?', [$from])
            ->selectRaw('COALESCE(SUM(jp.debit - jp.credit), 0) as bal')
            ->value('bal');
    }

    /** Base query of cash-leg postings, joined to JE + module row + contra signature. */
    private function cashMovements(array $cashAccountIds, ?int $branchId, string $from, string $to)
    {
        $contra = DB::table('journal_postings as jp')
            ->join('accounts as a', 'a.id', '=', 'jp.account_id')
            ->join('account_types as at', 'at.id', '=', 'a.account_type_id')
            ->whereNotIn('a.code', self::CASH_CODES)
            ->groupBy('jp.journal_entry_id')
            ->selectRaw('jp.journal_entry_id')
            ->selectRaw("MAX(CASE WHEN at.code = 'EXPENSE' THEN 1 ELSE 0 END) as has_expense")
            ->selectRaw("MAX(CASE WHEN at.code = 'INCOME'  THEN 1 ELSE 0 END) as has_income")
            ->selectRaw("MAX(CASE WHEN a.code = '1200' THEN 1 ELSE 0 END) as has_ar")
            ->selectRaw("MAX(CASE WHEN a.code = '2000' THEN 1 ELSE 0 END) as has_ap");

        return DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->leftJoin('cash_ledger_entries as cle', 'cle.journal_entry_id', '=', 'je.id')
            ->leftJoinSub($contra, 'c', 'c.journal_entry_id', '=', 'je.id')
            ->whereIn('jp.account_id', $cashAccountIds)
            ->when($branchId, fn ($q) => $q->where('je.branch_id', $branchId))
            ->whereRaw('DATE(' . self::EFF_DATE . ') >= ?', [$from])
            ->whereRaw('DATE(' . self::EFF_DATE . ') <= ?', [$to])
            ->selectRaw(self::EFF_DATE . ' as eff_date')
            ->selectRaw('jp.id, jp.journal_entry_id, jp.debit, jp.credit, je.memo, je.reference_type')
            ->selectRaw('cle.id as cle_id, cle.category as cle_category, cle.reference_name as cle_reference_name')
            ->selectRaw('cle.status as cle_status, cle.party_type as cle_party_type, cle.party_id as cle_party_id')
            ->selectRaw('c.has_expense, c.has_income, c.has_ar, c.has_ap');
    }

    /** For each journal entry, the counterparty (type,id) from its non-cash party leg. */
    private function partyLegFor(array $jeIds): array
    {
        if (empty($jeIds)) return [];

        $rows = DB::table('journal_postings')
            ->whereIn('journal_entry_id', $jeIds)
            ->whereNotNull('party_id')
            ->whereNotNull('party_type')
            ->get(['journal_entry_id', 'party_type', 'party_id']);

        $map = [];
        foreach ($rows as $r) {
            // first party leg wins (entries here have a single counterparty)
            $map[$r->journal_entry_id] ??= ['type' => $r->party_type, 'id' => (int) $r->party_id];
        }
        return $map;
    }

    /** Batch-resolve "Fqcn#id" => display name across User / Customer / Vendor. */
    private function resolveNames($rows, array $partyLeg): array
    {
        $wanted = []; // type => [ids]
        $add = function (?string $type, $id) use (&$wanted) {
            if ($type && $id) $wanted[$type][(int) $id] = true;
        };

        foreach ($rows as $r) {
            $add($r->cle_party_type, $r->cle_party_id);
            if (isset($partyLeg[$r->journal_entry_id])) {
                $add($partyLeg[$r->journal_entry_id]['type'], $partyLeg[$r->journal_entry_id]['id']);
            }
        }

        $tables = [
            \App\Models\User::class     => ['table' => 'users',     'cols' => ['name']],
            \App\Models\Customer::class => ['table' => 'customers', 'cols' => ['first_name', 'last_name']],
            \App\Models\Vendor::class   => ['table' => 'vendors',   'cols' => ['first_name', 'last_name', 'company_name']],
        ];

        $names = [];
        foreach ($wanted as $type => $idSet) {
            $cfg = $tables[$type] ?? null;
            if (!$cfg) continue;
            $rows2 = DB::table($cfg['table'])
                ->whereIn('id', array_keys($idSet))
                ->get(array_merge(['id'], $cfg['cols']));
            foreach ($rows2 as $row) {
                $parts = [];
                foreach ($cfg['cols'] as $col) {
                    $v = trim((string) ($row->$col ?? ''));
                    if ($v !== '') $parts[] = $v;
                }
                $label = trim(implode(' ', array_slice($parts, 0, 2)));
                if ($label === '' && !empty($parts)) $label = $parts[0];
                $names[$type . '#' . $row->id] = $label !== '' ? $label : (class_basename($type) . ' #' . $row->id);
            }
        }
        return $names;
    }

    /** @return array{0:string,1:string} [bucket, label] */
    private function classify(object $r, bool $isIn): array
    {
        // 1) Non-sales module rows carry an explicit category.
        if (!empty($r->cle_category)) {
            $cat = CashLedgerCategory::tryFrom($r->cle_category);
            if ($cat) {
                $b = $cat->summaryBucket();
                return [$b, self::BUCKET_LABELS[$b] ?? $cat->label()];
            }
        }

        $ref = $r->reference_type ? class_basename($r->reference_type) : null;

        if ($isIn) {
            if (in_array($ref, ['PurchaseClaimReceipt', 'PurchaseClaim'], true)) {
                return ['claim_receipts', self::BUCKET_LABELS['claim_receipts']];
            }
            // Customer payment received: Dr cash / Cr AR(1200); ref = Receipt
            if (($r->has_ar ?? 0) || ($r->has_income ?? 0) || in_array($ref, ['Receipt', 'Sale', 'DeliveryBoyReceived'], true)) {
                return ['customer_receipts', self::BUCKET_LABELS['customer_receipts']];
            }
            return ['other_in', self::BUCKET_LABELS['other_in']];
        }

        // OUT
        if (($r->has_ap ?? 0) || in_array($ref, ['VendorPayment', 'PurchasePayment', 'Purchase'], true)) {
            return ['vendor_payments', self::BUCKET_LABELS['vendor_payments']];
        }
        if (in_array($ref, ['SaleReturnRefund', 'SaleReturn'], true)) {
            return ['refunds_out', self::BUCKET_LABELS['refunds_out']];
        }
        if (($r->has_expense ?? 0)) {
            return ['business_expenses', self::BUCKET_LABELS['business_expenses']];
        }
        return ['other_out', self::BUCKET_LABELS['other_out']];
    }
}
