<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Response\ApiResponse;
use App\Models\Customer;
use App\Models\Receipt;
use App\Services\BranchContextService;
use App\Services\CustomerPaymentService;
use App\Services\LedgerService;
use App\Services\PartyPaymentReversalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request, BranchContextService $branches)
    {
        // ---- Inputs ----
        $page     = max(1, (int)$request->get('page', 1));
        $perPage  = max(1, min(500, (int)$request->get('per_page', 15)));
        $search   = trim((string)$request->get('search', ''));
        $balanceFilter = strtolower((string) $request->get('balance_filter', 'all'));
        if (!in_array($balanceFilter, ['all', 'outstanding', 'advance_credit'], true)) {
            abort(422, 'balance_filter must be one of: all, outstanding, advance_credit.');
        }
        $includeBalance = $request->boolean('include_balance') || $balanceFilter !== 'all';
        $branchId = $branches->effectiveBranchId($request); // optional

        // If you prefer an explicit flag name like ?with_balance=1, use that instead:
        // $includeBalance = $request->boolean('with_balance');

        // ---- Base query (cheap) ----
        $idQuery = Customer::query()->select('customers.id');

        if ($search !== '') {
            $idQuery->where(function ($q) use ($search) {
                $q->where('customers.first_name', 'like', "%{$search}%")
                    ->orWhere('customers.last_name',  'like', "%{$search}%")
                    ->orWhere('customers.email',      'like', "%{$search}%")
                    ->orWhere('customers.phone',      'like', "%{$search}%");
            });
        }

        if (Schema::hasColumn('customers', 'branch_id')) {
            $effectiveBranchId = $branches->effectiveBranchId($request);
            if (!$branches->isMasterAdmin($request->user()) && $effectiveBranchId) {
                $idQuery->where(fn ($q) => $q->where('customers.branch_id', $effectiveBranchId));
                // $idQuery->where(fn ($q) => $q->where('branch_id', $effectiveBranchId)->orWhereNull('branch_id'));
            } elseif ($branches->isMasterAdmin($request->user()) && $request->filled('branch_id')) {
                $idQuery->where(fn ($q) => $q->where('customers.branch_id', $effectiveBranchId));
                // $idQuery->where(fn ($q) => $q->where('branch_id', $effectiveBranchId)->orWhereNull('branch_id'));
            }
        }

        // Apply the actionable balance filter in SQL BEFORE count/pagination.
        // Customer trade balance is AR(1200) debit-credit; loans and all other
        // party-tagged accounts are deliberately excluded.
        if ($balanceFilter !== 'all') {
            $arAccountIds = DB::table('accounts')->where('code', '1200')->pluck('id')->all() ?: [0];
            $partyTypes = ['customer', Customer::class];
            $balanceSub = DB::table('journal_postings as bjp')
                ->join('journal_entries as bje', 'bje.id', '=', 'bjp.journal_entry_id')
                ->selectRaw('bjp.party_id, SUM(COALESCE(bjp.debit,0) - COALESCE(bjp.credit,0)) AS trade_balance')
                ->whereIn('bjp.party_type', $partyTypes)
                ->whereIn('bjp.account_id', $arAccountIds)
                ->when($branchId, fn ($q) => $q->where('bje.branch_id', $branchId))
                ->groupBy('bjp.party_id');

            $idQuery->joinSub($balanceSub, 'party_trade_balance', fn ($join) =>
                $join->on('party_trade_balance.party_id', '=', 'customers.id')
            );
            $balanceFilter === 'outstanding'
                ? $idQuery->where('party_trade_balance.trade_balance', '>', 0.004)
                : $idQuery->where('party_trade_balance.trade_balance', '<', -0.004);
        }

        // Light + indexable sort (tweak to your indexed columns)
        $idQuery->orderBy('customers.first_name')->orderBy('customers.last_name')->orderBy('customers.id');

        $total = (clone $idQuery)->count();

        // ---- Page of IDs ----
        $ids = (clone $idQuery)
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->pluck('id')
            ->all();

        // Early return if no rows
        if (empty($ids)) {
            return ApiResponse::success([
                'customers'     => [],
                'total'         => $total,
                'per_page'      => $perPage,
                'current_page'  => $page,
                'last_page'     => (int)ceil($total / $perPage),
            ], 'Customers fetched successfully');
        }

        // ---- Fetch models for those IDs (preserve order) ----
        $customersQuery = Customer::query()->whereIn('id', $ids);

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $customersQuery->orderByRaw('FIELD(id, ' . implode(',', array_map('intval', $ids)) . ')');
        } else {
            // SQLite/Postgres: ORDER BY CASE id WHEN ... THEN ... END
            $case = 'CASE id ' . collect($ids)
                ->map(fn($id, $i) => 'WHEN ' . (int)$id . ' THEN ' . (int)$i)
                ->implode(' ') . ' END';

            $customersQuery->orderByRaw($case);
        }

        $customers = $customersQuery->get();

        // ---- Optional: pull balances only for this page ----
        $balancesById = [];
        $balancesById = [];
        if ($includeBalance) {
            $customerFqcn = \App\Models\Customer::class;
            $partyTypes   = ['customer', $customerFqcn];

            $jp = DB::table('journal_postings as jp')
                ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
                ->selectRaw("
                    jp.party_id AS customer_id,
                    SUM(CASE WHEN jp.debit  > 0 THEN jp.debit  ELSE 0 END) AS tot_sales,
                    SUM(CASE WHEN jp.credit > 0 THEN jp.credit ELSE 0 END) AS tot_receipts,
                    SUM(jp.debit - jp.credit)                              AS balance,
                    MAX(COALESCE(jp.created_at, je.created_at))            AS last_activity_at
                ")
                ->whereIn('jp.party_type', $partyTypes)
                ->whereIn('jp.party_id', $ids);

            // Filter by branch on journal_entries
            if ($branchId > 0) {
                $jp->where('je.branch_id', $branchId);
            }

            // Commercial balance = Accounts Receivable control account (1200)
            // ONLY. A loan/Qameti/expense posting tagged with this customer must
            // never inflate their trade receivable.
            $arAccountIds = DB::table('accounts')->where('code', '1200')->pluck('id')->all();
            $jp->whereIn('jp.account_id', $arAccountIds ?: [0]);

            $balancesById = $jp->groupBy('jp.party_id')
                ->get()
                ->mapWithKeys(function ($row) {
                    return [(int)$row->customer_id => [
                        'total_sales'      => (float)$row->tot_sales,
                        'total_receipts'   => (float)$row->tot_receipts,
                        'balance'          => (float)$row->balance,
                        'last_activity_at' => $row->last_activity_at ? (string)$row->last_activity_at : null,
                    ]];
                })
                ->all();
        }

        // ---- Transform output ----
        // If your CustomerResource can accept extra meta, you can inject it there.
        // Otherwise merge balance fields here before wrapping in the resource.
        $outCustomers = $customers->map(function ($c) use ($includeBalance, $balancesById) {
            $base = (new CustomerResource($c))->toArray(request());

            if (!$includeBalance) {
                return $base;
            }

            $b = $balancesById[$c->id] ?? [
                'total_sales'      => 0.0,
                'total_receipts'   => 0.0,
                'balance'          => 0.0,
                'last_activity_at' => null,
            ];

            return array_merge($base, [
                'total_sales'      => $b['total_sales'],
                'total_receipts'   => $b['total_receipts'],
                'balance'          => $b['balance'],
                'last_activity_at' => $b['last_activity_at'],
            ]);
        });

        return ApiResponse::success([
            'customers'     => $outCustomers, // already a collection of arrays
            'total'         => $total,
            'per_page'      => $perPage,
            'current_page'  => $page,
            'last_page'     => (int)ceil($total / $perPage),
        ], 'Customers fetched successfully');
    }

    public function store(CustomerRequest $request, BranchContextService $branches)
    {
        $data = $request->validated();

        // if (isset($data['password'])) {
        //     $data['password'] = Hash::make($data['password']);
        // }
        $data['branch_id'] = $branches->requireBranchId($request);
        $customer = Customer::create($data);
        return ApiResponse::success(new CustomerResource($customer), 'Customer created successfully');
    }

    public function show(Request $request, Customer $customer, BranchContextService $branches)
    {
        if ($customer->branch_id) {
            $branches->assertCanAccessBranch($request, (int) $customer->branch_id);
        }

        $branchId = $branches->effectiveBranchId($request);

        // Trade summary is Accounts-Receivable-ONLY. Loans (1300), Qameti (1310)
        // and expenses tagged to this customer must never inflate their trade
        // balance. Both the trade summary and the separate loan summary come
        // from the single authoritative PartyBalanceService.
        $svc   = new \App\Services\PartyBalanceService();
        $trade = $svc->customerTrade($customer->id, $branchId);
        $loan  = $svc->loanSummary('customer', $customer->id, $branchId);

        $res = (new CustomerResource($customer))->toArray($request);

        // Explicit, unambiguous fields.
        $res['trade_debit']   = $trade['trade_debit'];
        $res['trade_credit']  = $trade['trade_credit'];
        $res['trade_balance'] = $trade['trade_balance'];
        $res['loan']          = $loan;

        // Legacy aliases kept for frontend compatibility — now AR-only values.
        $res['total_sales']    = $trade['trade_debit'];
        $res['total_receipts'] = $trade['trade_credit'];
        $res['balance']        = $trade['trade_balance'];

        return ApiResponse::success($res, 'Customer fetched successfully');
    }

    public function update(CustomerRequest $request, Customer $customer, BranchContextService $branches)
    {
        if ($customer->branch_id) {
            $branches->assertCanAccessBranch($request, (int) $customer->branch_id);
        }

        $data = $request->validated();
        $data['branch_id'] = $customer->branch_id ?: $branches->requireBranchId($request);
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $customer->update($data);
        return ApiResponse::success(new CustomerResource($customer), 'Customer updated successfully');
    }

    public function destroy(Request $request, Customer $customer, BranchContextService $branches)
    {
        if ($customer->branch_id) {
            $branches->assertCanAccessBranch($request, (int) $customer->branch_id);
        }

        $customer->delete();
        return ApiResponse::success(null, 'Customer deleted successfully');
    }

    public function sales(Request $request, Customer $customer, BranchContextService $branches)
    {
        $page     = max(1, (int)$request->get('page', 1));
        $perPage  = max(1, min(100, (int)$request->get('per_page', 15)));
        if ($customer->branch_id) {
            $branches->assertCanAccessBranch($request, (int) $customer->branch_id);
        }
        $branchId = $branches->effectiveBranchId($request);

        // Count
        $countQ = DB::table('sales')->where('customer_id', $customer->id);
        if ($branchId) $countQ->where('branch_id', $branchId);
        $total = (clone $countQ)->count();

        // Paged rows with allocated & open
        $rows = DB::table('sales as s')
            ->leftJoin('receipts as r', 'r.sale_id', '=', 's.id')
            ->selectRaw("
            s.id, s.invoice_no, s.invoice_date, s.branch_id, s.total,
            COALESCE(SUM(r.amount),0) AS allocated,
            (s.total - COALESCE(SUM(r.amount),0)) AS open_amount
        ")
            ->where('s.customer_id', $customer->id)
            ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
            ->groupBy('s.id', 's.invoice_no', 's.invoice_date', 's.branch_id', 's.total')
            ->orderByDesc('s.invoice_date')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(fn($r) => [
                'id'           => (int)$r->id,
                'invoice_no'   => $r->invoice_no,
                'invoice_date' => (string)$r->invoice_date,
                'branch_id'    => (int)$r->branch_id,
                'total'        => (float)$r->total,
                'allocated'    => (float)$r->allocated,
                'open_amount'  => (float)$r->open_amount,
            ]);

        return ApiResponse::success([
            'items'        => $rows,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int)ceil($total / $perPage),
        ], 'Customer sales fetched successfully');
    }

    public function receipts(Request $request, Customer $customer, BranchContextService $branches)
    {
        $page     = max(1, (int)$request->get('page', 1));
        $perPage  = max(1, min(100, (int)$request->get('per_page', 15)));
        if ($customer->branch_id) {
            $branches->assertCanAccessBranch($request, (int) $customer->branch_id);
        }
        $branchId = $branches->effectiveBranchId($request);

        // We support both 'customer' and FQCN saved in party_type
        $partyTypes = ['customer', \App\Models\Customer::class];

        // Receipts are AR-only: a loan recovery (1300 credit) tagged to this
        // customer must not appear as a trade receipt.
        $arAccountIds = DB::table('accounts')->where('code', '1200')->pluck('id')->all() ?: [0];

        // ---------- Count (credits to AR for this customer) ----------
        $countQ = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->whereIn('jp.party_type', $partyTypes)
            ->where('jp.party_id', $customer->id)
            ->whereIn('jp.account_id', $arAccountIds)
            ->where('jp.credit', '>', 0);

        if ($branchId) {
            $countQ->where('je.branch_id', $branchId);
        }

        $total = (clone $countQ)->count();

        // ---------- Page rows ----------
        // NOTE:
        // - We treat any jp.credit > 0 as a "receipt" (this covers receipts & credit notes)
        // - If you ONLY want cash/bank receipts (and not credit notes), you can filter
        //   by the offsetting account(s) via jp.account_id or je.type if you track it.
        $rows = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->select([
                'jp.id as posting_id',
                'jp.journal_entry_id',
                DB::raw('COALESCE(jp.created_at, je.entry_date, je.created_at) AS received_at'),
                'je.branch_id',
                // Optional meta if your schema has these on journal_entries:
                // 'je.method', 
                'je.memo',
                DB::raw('jp.credit AS amount'),
            ])
            ->whereIn('jp.party_type', $partyTypes)
            ->where('jp.party_id', $customer->id)
            ->whereIn('jp.account_id', $arAccountIds)
            ->where('jp.credit', '>', 0)
            ->when($branchId, fn($q) => $q->where('je.branch_id', $branchId))
            ->orderByDesc(DB::raw('COALESCE(jp.created_at, je.entry_date, je.created_at)'))
            ->orderByDesc('jp.id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(function ($r) {
                return [
                    // Present a stable "receipt-ish" identity using the posting/JE ids
                    'id'              => (int)$r->posting_id,
                    'journal_entry_id' => (int)$r->journal_entry_id,
                    'received_at'     => (string)$r->received_at,
                    'branch_id'       => (int)$r->branch_id,
                    'amount'          => (float)$r->amount,
                    // If you keep method/reference on journal_entries, expose them:
                    // 'method'       => $r->method,
                    'reference'    => $r->memo
                ];
            });

        return ApiResponse::success([
            'items'        => $rows,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int)ceil($total / $perPage),
        ], 'Customer receipts fetched successfully (from journal)');
    }

    public function ledger(Request $request, \App\Models\Customer $customer, BranchContextService $branches)
    {
        $page     = max(1, (int)$request->get('page', 1));
        $perPage  = max(1, min(100, (int)$request->get('per_page', 15)));
        if ($customer->branch_id) {
            $branches->assertCanAccessBranch($request, (int) $customer->branch_id);
        }
        $branchId = $branches->effectiveBranchId($request);

        // Optional date range
        $from = $request->date('from'); // e.g. 2025-10-01
        $to   = $request->date('to');   // e.g. 2025-10-31

        $svc = new LedgerService();

        $data = $svc->getLedger([
            'party_type' => 'customer',
            'customer_id' => $customer->id,
            'from' => $from,
            'to' => $to,
            'page' => $page,
            'per_page' => $perPage,
            'branch_id' => $branchId,
            'latest' => $request->boolean('latest'), // follow-latest => last page
        ]);

        $label = ucfirst($data['party_type']) . ' ledger fetched successfully';
        return ApiResponse::success($data, $label);
    }

    /**
     * Separate Loan Ledger for this customer as a borrower.
     * Restricted internally to Loans Receivable (1300); never touches AR.
     */
    public function loanLedger(Request $request, Customer $customer, BranchContextService $branches)
    {
        if ($customer->branch_id) {
            $branches->assertCanAccessBranch($request, (int) $customer->branch_id);
        }

        $data = $request->validate([
            'from'     => 'nullable|date',
            'to'       => 'nullable|date',
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'latest'   => 'nullable|boolean',
        ]);

        $svc  = new \App\Services\PartyBalanceService();
        $out  = $svc->loanLedger([
            'party_type' => 'customer',
            'party_id'   => $customer->id,
            'branch_id'  => $branches->effectiveBranchId($request),
            'from'       => $data['from'] ?? null,
            'to'         => $data['to'] ?? null,
            'page'       => $data['page'] ?? 1,
            'per_page'   => $data['per_page'] ?? 15,
            'latest'     => $request->boolean('latest'),
        ]);

        return ApiResponse::success($out, 'Customer loan ledger fetched successfully');
    }

    public function storeReceipt(Request $request, Customer $customer, CustomerPaymentService $cps, BranchContextService $branches)
    {
        $data = $request->validate([
            'amount'      => 'required|numeric|min:1',
            // Validated against the branch by the resolver in CustomerPaymentService.
            'method'      => ['required', 'string'],
            'reference'   => 'nullable|string',
            'branch_id' => 'nullable|integer',
            'received_by' => 'nullable|integer',
            'received_on' => 'nullable|date'
        ]);
        if ($customer->branch_id) {
            $branches->assertCanAccessBranch($request, (int) $customer->branch_id);
        }

        $reference = $data['reference'] ?? "Payment received by " . auth()->user()->name;
        $data['customer_id'] = $customer->id;
        $data['branch_id'] = $branches->requireBranchId($request);
        $data['reference'] = $reference;
        $data['memo'] = $reference;

        return DB::transaction(function () use ($data, $cps) {
            // $payment = $sale->payments()->create($data);
            $payment = $cps->create($data);


            return ApiResponse::success(
                ['payment' => $payment],
                'Payment added successfully'
            );
        });
    }

    public function reverseReceipt(
        Request $request,
        Customer $customer,
        Receipt $receipt,
        PartyPaymentReversalService $reversals,
        BranchContextService $branches
    ) {
        $data = $request->validate([
            'reason' => 'required|string|min:3|max:1000',
        ]);

        if ((int) $receipt->customer_id !== (int) $customer->id) {
            abort(404);
        }
        if ($receipt->branch_id) {
            $branches->assertCanAccessBranch($request, (int) $receipt->branch_id);
        }

        $receipt = $reversals->reverseCustomerReceipt(
            $receipt,
            trim($data['reason']),
            (int) $request->user()->id
        );

        return ApiResponse::success([
            'payment' => $receipt,
        ], 'Customer receipt reversed successfully. Record the correct receipt separately.');
    }
}
