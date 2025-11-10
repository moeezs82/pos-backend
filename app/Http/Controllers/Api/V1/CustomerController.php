<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Response\ApiResponse;
use App\Models\Customer;
use App\Services\CustomerPaymentService;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        // ---- Inputs ----
        $page     = max(1, (int)$request->get('page', 1));
        $perPage  = max(1, min(500, (int)$request->get('per_page', 15)));
        $search   = trim((string)$request->get('search', ''));
        $includeBalance = filter_var($request->boolean('include_balance'), FILTER_VALIDATE_BOOLEAN);
        $branchId = $request->integer('branch_id'); // optional

        // If you prefer an explicit flag name like ?with_balance=1, use that instead:
        // $includeBalance = $request->boolean('with_balance');

        // ---- Base query (cheap) ----
        $idQuery = Customer::query()->select('id');

        if ($search !== '') {
            $idQuery->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name',  'like', "%{$search}%")
                    ->orWhere('email',      'like', "%{$search}%")
                    ->orWhere('phone',      'like', "%{$search}%");
            });
        }

        // Light + indexable sort (tweak to your indexed columns)
        $idQuery->orderBy('first_name')->orderBy('last_name')->orderBy('id');

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
        $customers = Customer::query()
            ->whereIn('id', $ids)
            ->orderByRaw('FIELD(id, ' . implode(',', array_map('intval', $ids)) . ')')
            ->get();

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

            // Optional: restrict to AR accounts if needed
            // $jp->whereIn('jp.account_id', [1200,1201]);

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

    public function store(CustomerRequest $request)
    {
        $data = $request->validated();

        // if (isset($data['password'])) {
        //     $data['password'] = Hash::make($data['password']);
        // }
        $customer = Customer::create($data);
        return ApiResponse::success(new CustomerResource($customer), 'Customer created successfully');
    }

    public function show(Request $request, Customer $customer)
    {
        $branchId   = $request->integer('branch_id');
        $partyTypes = ['customer', \App\Models\Customer::class];

        $ar = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->selectRaw("
            SUM(CASE WHEN jp.debit  > 0 THEN jp.debit  ELSE 0 END) AS total_sales,
            SUM(CASE WHEN jp.credit > 0 THEN jp.credit ELSE 0 END) AS total_receipts,
            SUM(jp.debit - jp.credit) AS balance
        ")
            ->whereIn('jp.party_type', $partyTypes)
            ->where('jp.party_id', $customer->id)
            ->when($branchId, fn($q) => $q->where('je.branch_id', $branchId))
            ->first();

        $res = (new CustomerResource($customer))->toArray($request);

        // Attach just the simple totals
        $res['total_sales']    = (float)($ar->total_sales ?? 0);
        $res['total_receipts'] = (float)($ar->total_receipts ?? 0);
        $res['balance']        = (float)($ar->balance ?? 0);

        return ApiResponse::success($res, 'Customer fetched successfully');
    }

    public function update(CustomerRequest $request, Customer $customer)
    {
        $data = $request->validated();
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $customer->update($data);
        return ApiResponse::success(new CustomerResource($customer), 'Customer updated successfully');
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();
        return ApiResponse::success(null, 'Customer deleted successfully');
    }

    public function sales(Request $request, Customer $customer)
    {
        $page     = max(1, (int)$request->get('page', 1));
        $perPage  = max(1, min(100, (int)$request->get('per_page', 15)));
        $branchId = $request->integer('branch_id');

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

    public function receipts(Request $request, Customer $customer)
    {
        $page     = max(1, (int)$request->get('page', 1));
        $perPage  = max(1, min(100, (int)$request->get('per_page', 15)));
        $branchId = $request->integer('branch_id');

        // We support both 'customer' and FQCN saved in party_type
        $partyTypes = ['customer', \App\Models\Customer::class];

        // ---------- Count (credits to AR for this customer) ----------
        $countQ = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->whereIn('jp.party_type', $partyTypes)
            ->where('jp.party_id', $customer->id)
            ->where('jp.credit', '>', 0);

        // if ($branchId) {
        //     $countQ->where('je.branch_id', $branchId);
        // }

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
            ->where('jp.credit', '>', 0)
            // ->when($branchId, fn($q) => $q->where('je.branch_id', $branchId))
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

    public function ledger(Request $request, \App\Models\Customer $customer)
    {
        $page     = max(1, (int)$request->get('page', 1));
        $perPage  = max(1, min(100, (int)$request->get('per_page', 15)));
        $branchId = $request->integer('branch_id');

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
            'per_page' => $perPage
        ]);

        $label = ucfirst($data['party_type']) . ' ledger fetched successfully';
        return ApiResponse::success($data, $label);
    }

    public function storeReceipt(Request $request, Customer $customer, CustomerPaymentService $cps)
    {
        $data = $request->validate([
            'amount'      => 'required|numeric|min:1',
            'method'      => ['required', Rule::in(['cash', 'card', 'bank', 'wallet'])],
            'reference'   => 'nullable|string',
            'branch_id' => 'nullable|integer',
            'received_by' => 'nullable|integer',
            'received_on' => 'nullable|date'
        ]);
        $reference = $data['reference'] ?? "Payment received by " . auth()->user()->name;
        $data['customer_id'] = $customer->id;
        $data['branch_id'] = $request->branch_id;
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
}
