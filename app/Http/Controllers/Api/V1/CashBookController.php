<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\CashTransaction;
use App\Services\CashSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CashBookController extends Controller
{
    public function __construct(private CashSyncService $service) {}

    /**
     * Ledger with opening/closing + running balance (paginated)
     * account_id is optional (all accounts if omitted)
     *
     * Query params:
     * - account_id (optional)  -> when omitted, includes all accounts
     * - branch_id (optional)
     * - date_from (optional)   -> if omitted, no lower bound
     * - date_to (optional)     -> if omitted, no upper bound
     * - status: pending|approved|void (default: approved)
     * - source: sales|purchases (optional)
     * - type: receipt|payment|expense|transfer_in|transfer_out (optional)
     * - method: cash|card|bank|wallet... (optional)
     * - amount_min, amount_max (optional)
     * - search (reference|voucher_no|note) (optional)
     * - per_page (default 50, max 200), page
     */
    public function index(Request $request)
    {
        $request->validate([
            'account_id' => ['nullable', 'exists:accounts,id'],
            'branch_id'  => ['nullable', 'exists:branches,id'],
            'date_from'  => ['nullable', 'date'],
            'date_to'    => ['nullable', 'date', 'after_or_equal:date_from'],
            'status'     => ['nullable', Rule::in(['pending', 'approved', 'void'])],
            'source'     => ['nullable', Rule::in(['sales', 'purchases'])],
            'type'       => ['nullable', Rule::in(['receipt', 'payment', 'expense', 'transfer_in', 'transfer_out'])],
            'method'     => ['nullable', 'string', 'max:50'],
            'amount_min' => ['nullable', 'numeric', 'min:0'],
            'amount_max' => ['nullable', 'numeric', 'min:0'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:200'],
            'page'       => ['nullable', 'integer', 'min:1'],
        ]);

        $accountId = $request->account_id ? (int)$request->account_id : null;
        $branchId  = $request->branch_id ? (int)$request->branch_id : null;
        $from      = $request->date_from; // nullable
        $to        = $request->date_to;   // nullable
        $status    = $request->status ?? 'approved';
        $source    = $request->source;
        $type      = $request->type;
        $method    = $request->method;
        $amtMin    = $request->amount_min;
        $amtMax    = $request->amount_max;
        $search    = $request->get('search');
        $perPage   = (int) ($request->get('per_page', 50));
        $perPage   = max(1, min($perPage, 200));

        // Base query (ASC for meaningful running balance)
        $base = CashTransaction::query()
            ->with(['counterparty'])
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->when($branchId,  fn($q) => $q->where('branch_id', $branchId))
            ->when($from && $to, fn($q) => $q->whereBetween('txn_date', [$from, $to]))
            ->when($from && !$to, fn($q) => $q->where('txn_date', '>=', $from))
            ->when(!$from && $to, fn($q) => $q->where('txn_date', '<=', $to))
            ->when($status,   fn($q) => $q->where('status', $status))
            ->when($source === 'sales', fn($q) => $q->where('source_type', \App\Models\Payment::class))
            ->when($source === 'purchases', fn($q) => $q->where('source_type', \App\Models\PurchasePayment::class))
            ->when($type, fn($q) => $q->where('type', $type))
            ->when($method, fn($q) => $q->where('method', $method))
            ->when(isset($amtMin), fn($q) => $q->where('amount', '>=', $amtMin))
            ->when(isset($amtMax), fn($q) => $q->where('amount', '<=', $amtMax))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('reference', 'like', "%$search%")
                        ->orWhere('voucher_no', 'like', "%$search%")
                        ->orWhere('note', 'like', "%$search%");
                });
            })
            ->orderBy('txn_date', 'asc')
            ->orderBy('id', 'asc');

        // Overall totals for the whole filtered set (not just current page)
        $overallIn  = (clone $base)->whereIn('type', ['receipt', 'transfer_in'])->sum('amount');
        $overallOut = (clone $base)->whereIn('type', ['payment', 'expense', 'transfer_out'])->sum('amount');
        $overallNet = (float)$overallIn - (float)$overallOut;

        // Opening balance logic:
        // If date_from provided: opening = net of all approved rows BEFORE date_from (same account/branch scope)
        // If no date_from: opening = 0 (we're showing full history)
        if ($from) {
            $openingQuery = CashTransaction::query()
                ->when($accountId, fn($q) => $q->where('account_id', $accountId))
                ->when($branchId,  fn($q) => $q->where('branch_id', $branchId))
                ->where('status', $status) // keep same status filter
                ->where('txn_date', '<', $from);

            $openIn  = (clone $openingQuery)->whereIn('type', ['receipt', 'transfer_in'])->sum('amount');
            $openOut = (clone $openingQuery)->whereIn('type', ['payment', 'expense', 'transfer_out'])->sum('amount');
            $opening = (float)$openIn - (float)$openOut;
        } else {
            $opening = 0.0;
        }

        $closing = (float)$opening + (float)$overallNet;

        // Paginate
        $paginator  = (clone $base)->paginate($perPage);
        $items      = $paginator->items();

        if (empty($items)) {
            return ApiResponse::success([
                'opening_balance' => number_format($opening, 2, '.', ''),
                'inflow'          => number_format($overallIn, 2, '.', ''),
                'outflow'         => number_format($overallOut, 2, '.', ''),
                'net_change'      => number_format($overallNet, 2, '.', ''),
                'closing_balance' => number_format($closing, 2, '.', ''),
                'page_inflow'     => number_format(0, 2, '.', ''),
                'page_outflow'    => number_format(0, 2, '.', ''),
                'transactions'    => [],
                'pagination'      => [
                    'total'        => $paginator->total(),
                    'per_page'     => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                ],
            ]);
        }

        // Compute prefix sums for rows BEFORE this page to start running balance
        /** @var \App\Models\CashTransaction $first */
        $first = $items[0];
        $prefix = CashTransaction::query()
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->when($branchId,  fn($q) => $q->where('branch_id', $branchId))
            ->when($from && $to, fn($q) => $q->whereBetween('txn_date', [$from, $to]))
            ->when($from && !$to, fn($q) => $q->where('txn_date', '>=', $from))
            ->when(!$from && $to, fn($q) => $q->where('txn_date', '<=', $to))
            ->where('status', $status)
            ->when($source === 'sales', fn($q) => $q->where('source_type', \App\Models\Payment::class))
            ->when($source === 'purchases', fn($q) => $q->where('source_type', \App\Models\PurchasePayment::class))
            ->when($type, fn($q) => $q->where('type', $type))
            ->when($method, fn($q) => $q->where('method', $method))
            ->when(isset($amtMin), fn($q) => $q->where('amount', '>=', $amtMin))
            ->when(isset($amtMax), fn($q) => $q->where('amount', '<=', $amtMax))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('reference', 'like', "%$search%")
                        ->orWhere('voucher_no', 'like', "%$search%")
                        ->orWhere('note', 'like', "%$search%");
                });
            })
            ->where(function ($q) use ($first) {
                $q->where('txn_date', '<', $first->txn_date)
                    ->orWhere(function ($qq) use ($first) {
                        $qq->where('txn_date', $first->txn_date)
                            ->where('id', '<', $first->id);
                    });
            });

        $prefixIn  = (clone $prefix)->whereIn('type', ['receipt', 'transfer_in'])->sum('amount');
        $prefixOut = (clone $prefix)->whereIn('type', ['payment', 'expense', 'transfer_out'])->sum('amount');

        $running = (float)$opening + ((float)$prefixIn - (float)$prefixOut);

        // Build page rows with running balance
        $rows       = [];
        $pageInflow = 0.0;
        $pageOutflow = 0.0;

        foreach ($items as $t) {
            /** @var \App\Models\CashTransaction $t */
            $delta = $t->isInflow() ? (float)$t->amount : -(float)$t->amount;
            $running += $delta;
            if ($delta > 0) $pageInflow += (float)$t->amount;
            else $pageOutflow += (float)$t->amount;

            $rows[] = [
                'id'               => $t->id,
                'date'             => $t->txn_date->toDateString(),
                'type'             => $t->type,
                'amount'           => number_format((float)$t->amount, 2, '.', ''),
                'method'           => $t->method,
                'reference'        => $t->reference,
                'voucher_no'       => $t->voucher_no,
                'note'             => $t->note,
                'status'           => $t->status,
                'source'           => class_basename($t->source_type),
                'source_id'        => $t->source_id,
                'counterparty'     => $t->counterparty?->only(['id', 'first_name', 'last_name', 'name']),
                'running_balance'  => number_format($running, 2, '.', ''),
            ];
        }

        return ApiResponse::success([
            // Overall (whole filtered range)
            'opening_balance' => number_format($opening, 2, '.', ''),
            'inflow'          => number_format($overallIn, 2, '.', ''),
            'outflow'         => number_format($overallOut, 2, '.', ''),
            'net_change'      => number_format($overallNet, 2, '.', ''),
            'closing_balance' => number_format($closing, 2, '.', ''),

            // Page-only totals
            'page_inflow'     => number_format($pageInflow, 2, '.', ''),
            'page_outflow'    => number_format($pageOutflow, 2, '.', ''),

            // Page data
            'transactions'    => $rows,
            'pagination'      => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function dailySummary(Request $request)
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:200'],
            'page'      => ['nullable', 'integer', 'min:1'],
        ]);

        $branch_id    = $request->branch_id;
        $from    = $request->date_from;
        $to      = $request->date_to;
        $perPage = max(1, min((int)($request->get('per_page', 30)), 200));

        // ---------- Opening (before date_from), approved only ----------
        $opening = 0.0;
        if ($from) {
            $openIn = DB::table('cash_transactions')
                ->where('branch_id', $branch_id)
                ->where('status', 'approved')
                ->where('txn_date', '<', $from)
                ->whereIn('type', ['receipt', 'transfer_in'])
                ->sum('amount');

            $openOut = DB::table('cash_transactions')
                ->where('branch_id', $branch_id)
                ->where('status', 'approved')
                ->where('txn_date', '<', $from)
                ->whereIn('type', ['payment', 'transfer_out', 'expense'])
                ->sum('amount');

            $opening = (float)$openIn - (float)$openOut;
        }

        // ---------- Level 1: per-day aggregates over the selected range ----------
        // ONE grouped query over the range (status + date only)
        $dailyAgg = DB::table('cash_transactions')
            ->where('status', 'approved')
            ->when($branch_id, fn($q) => $q->where('branch_id', $branch_id))
            ->when($from && $to, fn($q) => $q->whereBetween('txn_date', [$from, $to]))
            ->when($from && !$to, fn($q) => $q->where('txn_date', '>=', $from))
            ->when(!$from && $to, fn($q) => $q->where('txn_date', '<=', $to))
            ->selectRaw('DATE(txn_date) AS d')
            ->selectRaw("SUM(CASE WHEN type IN ('receipt','transfer_in') THEN amount ELSE 0 END) AS payment_in")
            ->selectRaw("SUM(CASE WHEN type IN ('payment','transfer_out') THEN amount ELSE 0 END) AS payment_out")
            ->selectRaw("SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense")
            ->groupBy('d');

        // ---------- Grand totals over the selected range (from the tiny daily set) ----------
        $totRow = DB::query()
            ->fromSub($dailyAgg, 'tot')
            ->selectRaw('COALESCE(SUM(payment_in),0)  AS t_in')
            ->selectRaw('COALESCE(SUM(payment_out),0) AS t_out')
            ->selectRaw('COALESCE(SUM(expense),0)    AS t_exp')
            ->selectRaw('COALESCE(SUM(payment_in - (payment_out + expense)),0) AS t_net')
            ->first();
        $totalIn      = (float)($totRow->t_in  ?? 0);
        $totalPayOut  = (float)($totRow->t_out ?? 0);
        $totalExpense = (float)($totRow->t_exp ?? 0);
        $totalNet     = (float)($totRow->t_net ?? 0);

        // ---------- Level 2: add net ----------
        $dailyWithNet = DB::query()
            ->fromSub($dailyAgg, 'a')
            ->selectRaw('d, payment_in, payment_out, expense, (payment_in - (payment_out + expense)) AS net');

        // ---------- Level 3: cumulative net (window function) ----------
        $dailyWithCum = DB::query()
            ->fromSub($dailyWithNet, 'b')
            ->selectRaw('d, payment_in, payment_out, expense, net')
            ->selectRaw('SUM(net) OVER (ORDER BY d ASC ROWS UNBOUNDED PRECEDING) AS cum_net');

        // ---------- Final rows with opening/closing ----------
        $final = DB::query()
            ->fromSub($dailyWithCum, 'c')
            ->selectRaw('d, payment_in, payment_out, expense, net')
            ->selectRaw('? + cum_net - net AS opening_day', [$opening])
            ->selectRaw('? + cum_net       AS closing',     [$opening]);

        // Paginate newest-first
        $paginator = $final->orderBy('d', 'desc')->paginate($perPage);

        // Page totals + rows (formatted as strings)
        $rows = [];
        $pageIn = $pageOut = $pageExp = $pageNet = 0.0;

        foreach ($paginator->items() as $r) {
            $pi = (float)$r->payment_in;
            $po = (float)$r->payment_out;
            $ex = (float)$r->expense;
            $nt = (float)$r->net;
            $op = (float)$r->opening_day;
            $cl = (float)$r->closing;

            $pageIn  += $pi;
            $pageOut += $po;
            $pageExp += $ex;
            $pageNet += $nt;

            $rows[] = [
                'date'        => (string)$r->d,
                'payment_in'  => number_format($pi, 2, '.', ''),
                'payment_out' => number_format($po, 2, '.', ''),
                'expense'     => number_format($ex, 2, '.', ''),
                'net'         => number_format($nt, 2, '.', ''),
                'opening'     => number_format($op, 2, '.', ''),
                'closing'     => number_format($cl, 2, '.', ''),
            ];
        }

        // Overall closing across the full selected range: last cumulative closing
        $lastRow = DB::query()
            ->fromSub($dailyWithCum, 'c')
            ->selectRaw('? + cum_net AS closing', [$opening])
            ->orderBy('d', 'desc')
            ->limit(1)
            ->first();
        $closingOverall = $lastRow ? (float)$lastRow->closing : (float)$opening;

        return ApiResponse::success([
            'opening_balance' => number_format($opening, 2, '.', ''),
            'totals' => [
                'payment_in'  => number_format($totalIn, 2, '.', ''),
                'payment_out' => number_format($totalPayOut, 2, '.', ''),
                'expense'     => number_format($totalExpense, 2, '.', ''),
                'net'         => number_format($totalNet, 2, '.', ''),
                'closing'     => number_format($closingOverall, 2, '.', ''),
            ],
            'page_totals' => [
                'payment_in'  => number_format($pageIn, 2, '.', ''),
                'payment_out' => number_format($pageOut, 2, '.', ''),
                'expense'     => number_format($pageExp, 2, '.', ''),
                'net'         => number_format($pageNet, 2, '.', ''),
            ],
            'rows' => $rows,
            'pagination' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function dailyDetails(Request $request)
    {
        $request->validate([
            'date'        => ['nullable', 'date'],
            'account_id'  => ['nullable', 'integer'],
            'branch_id'   => ['nullable', 'integer'],
            'type'        => ['nullable', 'in:receipt,payment,expense,transfer_in,transfer_out'],
            'method'      => ['nullable', 'string'],
            'party_kind'  => ['nullable', 'in:customer,vendor,none'], // filter by counterparty type
            'search'      => ['nullable', 'string', 'max:200'],
            'sort'        => ['nullable', 'in:created_at,amount'],
            'order'       => ['nullable', 'in:asc,desc'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:200'],
            'page'        => ['nullable', 'integer', 'min:1'],
        ]);

        $day      = $request->date ?: now()->toDateString();
        $perPage  = max(1, min((int)($request->get('per_page', 50)), 200));
        $sort     = $request->get('sort', 'created_at');
        $order    = $request->get('order', 'asc');
        $search   = trim((string)$request->get('search', ''));

        // ---------- Opening balance before the day ----------
        $openIn = DB::table('cash_transactions')
            ->where('status', 'approved')
            ->where('txn_date', '<', $day)
            ->when($request->account_id, fn($q, $v) => $q->where('account_id', $v))
            ->when($request->branch_id,  fn($q, $v) => $q->where('branch_id', $v))
            ->whereIn('type', ['receipt', 'transfer_in'])
            ->sum('amount');

        $openOut = DB::table('cash_transactions')
            ->where('status', 'approved')
            ->where('txn_date', '<', $day)
            ->when($request->account_id, fn($q, $v) => $q->where('account_id', $v))
            ->when($request->branch_id,  fn($q, $v) => $q->where('branch_id', $v))
            ->whereIn('type', ['payment', 'transfer_out', 'expense'])
            ->sum('amount');

        $opening = (float)$openIn - (float)$openOut;

        // ---------- Base query for the selected day (APPROVED only) ----------
        $base = DB::table('cash_transactions AS t')
            ->leftJoin('accounts AS a', 'a.id', '=', 't.account_id')
            ->leftJoin('branches AS b', 'b.id', '=', 't.branch_id')

            // Polymorphic counterparty
            ->leftJoin('customers AS cu', function ($join) {
                $join->on('cu.id', '=', 't.counterparty_id')
                    ->where('t.counterparty_type', '=', 'App\\Models\\Customer');
            })
            ->leftJoin('vendors AS ve', function ($join) {
                $join->on('ve.id', '=', 't.counterparty_id')
                    ->where('t.counterparty_type', '=', 'App\\Models\\Vendor');
            })

            // Link Payment -> Sale -> Vendor (used when source_type = Payment)
            ->leftJoin('payments AS pay', function ($join) {
                $join->on('pay.id', '=', 't.source_id')
                    ->where('t.source_type', '=', 'App\\Models\\Payment');
            })
            ->leftJoin('sales AS s', 's.id', '=', 'pay.sale_id')
            ->leftJoin('vendors AS sv', 'sv.id', '=', 's.vendor_id') // vendor via sale

            // Transfer pair (other account)
            ->leftJoin('cash_transactions AS tpair', function ($join) {
                $join->on('tpair.voucher_no', '=', 't.voucher_no')
                    ->on('tpair.txn_date', '=', 't.txn_date')
                    ->whereColumn('tpair.id', '!=', 't.id')
                    ->where('tpair.status', '=', 'approved');
            })
            ->leftJoin('accounts AS a_other', 'a_other.id', '=', 'tpair.account_id')

            ->where('t.status', 'approved')
            ->whereDate('t.txn_date', '=', $day)
            ->when($request->account_id, fn($q, $v) => $q->where('t.account_id', $v))
            ->when($request->branch_id,  fn($q, $v) => $q->where('t.branch_id', $v))
            ->when($request->type,       fn($q, $v) => $q->where('t.type', $v))
            ->when($request->method,     fn($q, $v) => $q->where('t.method', $v))

            // party_kind filter:
            // - customer: explicit customer counterparty
            // - vendor: explicit vendor counterparty OR (source_type=Payment AND sale->vendor exists)
            // - none: no explicit counterparty AND (if source_type=Payment) no sale vendor
            ->when($request->party_kind === 'customer', fn($q) =>
            $q->where('t.counterparty_type', 'App\\Models\\Customer'))
            ->when($request->party_kind === 'vendor', fn($q) =>
            $q->where(function ($q2) {
                $q2->where('t.counterparty_type', 'App\\Models\\Vendor')
                    ->orWhere(function ($q3) {
                        $q3->where('t.source_type', 'App\\Models\\Payment')
                            ->whereNotNull('s.vendor_id');
                    });
            }))
            ->when($request->party_kind === 'none', fn($q) =>
            $q->whereNull('t.counterparty_type')
                ->where(function ($q2) {
                    $q2->where('t.source_type', '!=', 'App\\Models\\Payment')
                        ->orWhereNull('s.vendor_id');
                }))

            // Search (also include sale vendor name and sale invoice)
            ->when(strlen($search) > 0, function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('t.reference', 'like', "%{$search}%")
                        ->orWhere('t.voucher_no', 'like', "%{$search}%")
                        ->orWhere('t.note', 'like', "%{$search}%")
                        ->orWhere('a.name', 'like', "%{$search}%")
                        ->orWhere('b.name', 'like', "%{$search}%")
                        ->orWhere('cu.first_name', 'like', "%{$search}%")
                        ->orWhere('cu.last_name',  'like', "%{$search}%")
                        ->orWhere('ve.first_name', 'like', "%{$search}%")
                        ->orWhere('sv.first_name', 'like', "%{$search}%")
                        ->orWhere('s.invoice_no',  'like', "%{$search}%");
                });
            })

            // Selects
            ->selectRaw('t.id')
            ->selectRaw('t.created_at, t.txn_date, t.type, t.amount, t.method, t.reference, t.voucher_no, t.note')
            ->selectRaw('t.account_id, a.name AS account_name')
            ->selectRaw('t.branch_id, b.name AS branch_name')
            ->selectRaw('t.counterparty_type, t.counterparty_id')

            ->selectRaw("CASE 
            WHEN t.counterparty_type = 'App\\\Models\\\Customer' THEN 'customer'
            WHEN t.counterparty_type = 'App\\\Models\\\Vendor'   THEN 'vendor'
            ELSE 'none'
         END AS counterparty_kind")

            ->selectRaw("TRIM(CONCAT(COALESCE(cu.first_name,''),' ',COALESCE(cu.last_name,''))) AS customer_name")
            ->selectRaw("ve.id AS vendor_cp_id")
            ->selectRaw("ve.first_name AS vendor_cp_name")

            // sale context + vendor via sale
            ->selectRaw('s.id AS sale_id')
            ->selectRaw('s.invoice_no AS sale_invoice_no')
            ->selectRaw('sv.id AS vendor_sale_id')
            ->selectRaw('sv.first_name AS vendor_sale_name')

            ->selectRaw("COALESCE(
            NULLIF(TRIM(CONCAT(COALESCE(cu.first_name,''),' ',COALESCE(cu.last_name,''))), ''),
            ve.first_name
         ) AS counterparty_name")

            ->selectRaw("CASE 
            WHEN t.type IN ('receipt','transfer_in') THEN 'in'
            WHEN t.type IN ('payment','transfer_out','expense') THEN 'out'
            ELSE 'out'
         END AS direction")
            ->selectRaw("CASE WHEN t.type IN ('receipt','transfer_in') THEN t.amount ELSE 0 END AS amount_in")
            ->selectRaw("CASE WHEN t.type IN ('payment','transfer_out','expense') THEN t.amount ELSE 0 END AS amount_out")
            ->selectRaw("CASE 
            WHEN t.type IN ('receipt','transfer_in') THEN t.amount
            WHEN t.type IN ('payment','transfer_out','expense') THEN -t.amount
            ELSE 0
         END AS amount_signed")

            ->selectRaw('a_other.name AS transfer_other_account');

        // ---------- Day totals ----------
        $totals = DB::query()
            ->fromSub($base, 'x')
            ->selectRaw('COALESCE(SUM(amount_in),0)  AS t_in')
            ->selectRaw('COALESCE(SUM(amount_out),0) AS t_out')
            ->selectRaw('COALESCE(SUM(CASE WHEN type = "expense" THEN amount_out ELSE 0 END),0) AS t_expense')
            ->selectRaw('COALESCE(SUM(amount_signed),0) AS t_net')
            ->first();

        $dayIn      = (float)($totals->t_in ?? 0);
        $dayOut     = (float)($totals->t_out ?? 0);
        $dayExpense = (float)($totals->t_expense ?? 0);
        $dayNet     = (float)($totals->t_net ?? 0);

        $openingDay = $opening;
        $closingDay = $openingDay + $dayNet;

        // ---------- Paginate rows ----------
        $paginator = DB::query()
            ->fromSub($base, 'r')
            ->orderBy($sort, $order)
            ->paginate($perPage);

        $rows = [];
        foreach ($paginator->items() as $r) {
            // Compute top-level vendor:
            // 1) explicit vendor counterparty
            // 2) else vendor via sale when source_type=Payment (already joined)
            $vendorId   = $r->vendor_cp_id ?: ($r->vendor_sale_id ?: null);
            $vendorName = $r->vendor_cp_name ?: ($r->vendor_sale_name ?: null);
            $vendorVia  = $r->vendor_cp_id ? 'counterparty' : ($r->vendor_sale_id ? 'sale' : null);

            $vendor = null;
            if (!empty($vendorId)) {
                $vendor = [
                    'id'   => (int)$vendorId,
                    'name' => $vendorName,
                    'via'  => $vendorVia,
                ];
            }

            $rows[] = [
                'id'                 => (int)$r->id,
                'date'               => (string)$r->txn_date,
                'created_at'         => (string)$r->created_at,
                'type'               => (string)$r->type,
                'direction'          => (string)$r->direction,
                'amount'             => number_format((float)$r->amount, 2, '.', ''),
                'amount_signed'      => number_format((float)$r->amount_signed, 2, '.', ''),
                'method'             => $r->method,
                'reference'          => $r->reference,
                'voucher_no'         => $r->voucher_no,
                'note'               => $r->note,

                'account' => [
                    'id'   => (int)$r->account_id,
                    'name' => $r->account_name,
                ],
                'branch' => $r->branch_id ? [
                    'id'   => (int)$r->branch_id,
                    'name' => $r->branch_name,
                ] : null,

                'counterparty' => [
                    'kind' => $r->counterparty_kind, // customer|vendor|none
                    'id'   => $r->counterparty_id ? (int)$r->counterparty_id : null,
                    'name' => $r->counterparty_name,
                ],

                // ✅ expose vendor here (explicit or via sale)
                'vendor' => $vendor,

                // transfers helper
                'transfer' => [
                    'other_account' => $r->transfer_other_account,
                ],

                // sale context (already shown in your response)
                'sale' => $r->sale_id ? [
                    'id'         => (int)$r->sale_id,
                    'invoice_no' => $r->sale_invoice_no,
                ] : null,
            ];
        }

        return ApiResponse::success([
            'date'    => $day,
            'opening' => number_format($openingDay, 2, '.', ''),
            'closing' => number_format($closingDay, 2, '.', ''),
            'totals'  => [
                'in'      => number_format($dayIn, 2, '.', ''),
                'out'     => number_format($dayOut, 2, '.', ''),
                'expense' => number_format($dayExpense, 2, '.', ''),
                'net'     => number_format($dayNet, 2, '.', ''),
            ],
            'filters' => [
                'account_id' => $request->account_id,
                'branch_id'  => $request->branch_id,
                'type'       => $request->type,
                'method'     => $request->method,
                'party_kind' => $request->party_kind,
                'search'     => $search,
                'sort'       => $sort,
                'order'      => $order,
            ],
            'rows'       => $rows,
            'pagination' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Create a direct EXPENSE entry in cash book
     * Either send account_id OR method (which will map to an account).
     * (So yes, you can do it WITHOUT account_id — just pass method.)
     */
    public function storeExpense(Request $request)
    {
        $data = $request->validate([
            'account_id' => ['nullable', 'exists:accounts,id'],
            'method'     => ['nullable', 'string', 'max:50'], // required if account_id is null
            'amount'     => ['required', 'numeric', 'min:0.01'],
            'txn_date'   => ['nullable', 'date'],
            // 'branch_id'  => ['nullable', 'exists:branches,id'],
            'reference'  => ['nullable', 'string', 'max:190'],
            'note'       => ['nullable', 'string'],
            'status'     => ['nullable', Rule::in(['pending', 'approved'])],
            'counterparty_type' => ['nullable', 'string'],   // e.g. "App\\Models\\Vendor"
            'counterparty_id'   => ['nullable', 'integer'],
        ]);

        if (empty($data['account_id']) && empty($data['method'])) {
            return ApiResponse::error('Either account_id or method is required.', 422);
        }

        $txn = $this->service->createExpense($data);

        return ApiResponse::success($txn, 201);
    }
}
