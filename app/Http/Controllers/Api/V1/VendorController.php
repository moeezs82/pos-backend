<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\VendorRequest;
use App\Http\Resources\VendorResource;
use App\Http\Response\ApiResponse;
use App\Models\Vendor;
use App\Services\LedgerService;
use App\Services\VendorPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class VendorController extends Controller
{
    public function index(Request $request)
    {
        // ---- Inputs ----
        $page           = max(1, (int)$request->get('page', 1));
        $perPage        = max(1, min(500, (int)$request->get('per_page', 15)));
        $search         = trim((string)$request->get('search', ''));
        $includeBalance = filter_var($request->boolean('include_balance'), FILTER_VALIDATE_BOOLEAN);
        $branchId       = $request->integer('branch_id'); // optional

        // ---- Base query (cheap) ----
        $idQuery = Vendor::query()->select('id');

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
                'vendors'      => [],
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int)ceil($total / $perPage),
            ], 'Vendors fetched successfully');
        }

        // ---- Fetch models for those IDs (preserve order) ----
        $vendorsQuery = Vendor::query()->whereIn('id', $ids);

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $vendorsQuery->orderByRaw('FIELD(id, ' . implode(',', array_map('intval', $ids)) . ')');
        } else {
            // SQLite/Postgres: ORDER BY CASE id WHEN ... THEN ... END
            $case = 'CASE id ' . collect($ids)
                ->map(fn($id, $i) => 'WHEN ' . (int)$id . ' THEN ' . (int)$i)
                ->implode(' ') . ' END';

            $vendorsQuery->orderByRaw($case);
        }

        $vendors = $vendorsQuery->get();

        // ---- Optional: pull balances only for this page (AP from GL) ----
        $balancesById = [];
        if ($includeBalance) {
            $vendorFqcn =
                Vendor::class;
            $partyTypes = ['vendor', $vendorFqcn];

            $jp = DB::table('journal_postings as jp')
                ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
                ->selectRaw("
                jp.party_id AS vendor_id,
                SUM(CASE WHEN jp.credit > 0 THEN jp.credit ELSE 0 END) AS tot_purchases,
                SUM(CASE WHEN jp.debit  > 0 THEN jp.debit  ELSE 0 END) AS tot_payments,
                SUM(jp.credit - jp.debit)                              AS balance,
                MAX(COALESCE(jp.created_at, je.entry_date, je.created_at)) AS last_activity_at
            ")
                ->whereIn('jp.party_type', $partyTypes)
                ->whereIn('jp.party_id', $ids);

            if ($branchId > 0) {
                $jp->where('je.branch_id', $branchId);
            }

            // Optional: restrict to AP control accounts only, if you want
            // $jp->whereIn('jp.account_id', [2100, 2110]);

            $balancesById = $jp->groupBy('jp.party_id')
                ->get()
                ->mapWithKeys(function ($row) {
                    return [(int)$row->vendor_id => [
                        'total_purchases' => (float)$row->tot_purchases,
                        'total_payments'  => (float)$row->tot_payments,
                        'balance'         => (float)$row->balance,
                        'last_activity_at' => $row->last_activity_at ? (string)$row->last_activity_at : null,
                    ]];
                })
                ->all();
        }

        // ---- Transform output ----
        $outVendors = $vendors->map(function ($v) use ($includeBalance, $balancesById) {
            $base = (new VendorResource($v))->toArray(request());

            if (!$includeBalance) {
                return $base;
            }

            $b = $balancesById[$v->id] ?? [
                'total_purchases' => 0.0,
                'total_payments'  => 0.0,
                'balance'         => 0.0,
                'last_activity_at' => null,
            ];

            return array_merge($base, [
                'total_purchases' => $b['total_purchases'],
                'total_payments'  => $b['total_payments'],
                'balance'         => $b['balance'],
                'last_activity_at' => $b['last_activity_at'],
            ]);
        });

        return ApiResponse::success([
            'vendors'      => $outVendors, // already a collection of arrays
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int)ceil($total / $perPage),
        ], 'Vendors fetched successfully');
    }

    public function store(VendorRequest $request)
    {
        $data = $request->validated();

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $vendor = Vendor::create($data);
        return ApiResponse::success(new VendorResource($vendor), 'Vendor created successfully');
    }

    public function show(Request $request, Vendor $vendor)
    {
        $branchId   = $request->integer('branch_id');
        $partyTypes = ['vendor', Vendor::class];

        // For Vendors (AP):
        // - Purchases typically CREDIT the vendor (increase AP)
        // - Payments/returns typically DEBIT the vendor (decrease AP)
        $ap = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->selectRaw("
            SUM(CASE WHEN jp.credit > 0 THEN jp.credit ELSE 0 END) AS total_purchases,
            SUM(CASE WHEN jp.debit  > 0 THEN jp.debit  ELSE 0 END) AS total_payments,
            SUM(jp.credit - jp.debit) AS balance
        ")
            ->whereIn('jp.party_type', $partyTypes)
            ->where('jp.party_id', $vendor->id)
            // ->when($branchId, fn($q) => $q->where('je.branch_id', $branchId))
            ->first();

        $res = (new VendorResource($vendor))->toArray($request);

        // Attach simple totals (floats for consistency)
        $res['total_purchases'] = (float) ($ap->total_purchases ?? 0);
        $res['total_payments']  = (float) ($ap->total_payments  ?? 0);
        $res['balance']         = (float) ($ap->balance         ?? 0); // >0 = payable

        return ApiResponse::success($res, 'Vendor fetched successfully');
    }

    public function update(VendorRequest $request, Vendor $vendor)
    {
        $data = $request->validated();
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $vendor->update($data);
        return ApiResponse::success(new VendorResource($vendor), 'Vendor updated successfully');
    }

    public function destroy(Vendor $vendor)
    {
        $vendor->delete();
        return ApiResponse::success(null, 'Vendor deleted successfully');
    }

    public function purchases(
        Request $request,
        Vendor $vendor
    ) {
        $page     = max(1, (int)$request->get('page', 1));
        $perPage  = max(1, min(100, (int)$request->get('per_page', 15)));
        $branchId = $request->integer('branch_id');

        // Count
        $countQ = DB::table('purchases')->where('vendor_id', $vendor->id);
        if ($branchId) $countQ->where('branch_id', $branchId);
        $total = (clone $countQ)->count();

        // Paged rows with allocated & open
        $rows = DB::table('purchases as p')
            ->leftJoin('vendor_payments as vp', 'vp.purchase_id', '=', 'p.id') // <— rename if needed
            ->selectRaw("
            p.id, p.invoice_no, p.invoice_date, p.branch_id, p.total,
            COALESCE(SUM(vp.amount), 0) AS allocated,
            (p.total - COALESCE(SUM(vp.amount), 0)) AS open_amount
        ")
            ->where('p.vendor_id', $vendor->id)
            ->when($branchId, fn($q) => $q->where('p.branch_id', $branchId))
            ->groupBy('p.id', 'p.invoice_no', 'p.invoice_date', 'p.branch_id', 'p.total')
            ->orderByDesc('p.invoice_date')
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
        ], 'Vendor purchases fetched successfully');
    }

    public function payments(
        Request $request,
        Vendor $vendor
    ) {
        $page     = max(1, (int)$request->get('page', 1));
        $perPage  = max(1, min(100, (int)$request->get('per_page', 15)));
        $branchId = $request->integer('branch_id');

        // Support both 'vendor' and FQCN in party_type
        $partyTypes = [
            'vendor',
            Vendor::class
        ];

        // ---------- Count (debits to AP for this vendor) ----------
        $countQ = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->whereIn('jp.party_type', $partyTypes)
            ->where('jp.party_id', $vendor->id)
            ->where('jp.debit', '>', 0);
        // ->when($branchId, fn($q) => $q->where('je.branch_id', $branchId));

        $total = (clone $countQ)->count();

        // ---------- Page rows ----------
        $rows = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->select([
                'jp.id as posting_id',
                'jp.journal_entry_id',
                DB::raw('COALESCE(je.entry_date, je.created_at) AS paid_at'),
                'je.branch_id',
                'je.memo',
                DB::raw('jp.debit AS amount'),
            ])
            ->whereIn('jp.party_type', $partyTypes)
            ->where('jp.party_id', $vendor->id)
            ->where('jp.debit', '>', 0)
            // ->when($branchId, fn($q) => $q->where('je.branch_id', $branchId))
            ->orderByDesc(DB::raw('COALESCE(je.entry_date, je.created_at)'))
            ->orderByDesc('jp.id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(fn($r) => [
                'id'               => (int)$r->posting_id,
                'journal_entry_id' => (int)$r->journal_entry_id,
                'paid_at'          => (string)$r->paid_at,
                'branch_id'        => (int)$r->branch_id,
                'amount'           => (float)$r->amount,
                // Expose any extra meta you store on journal_entries:
                // 'method'        => $r->method,
                'reference'        => $r->memo,
            ]);

        return ApiResponse::success([
            'items'        => $rows,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int)ceil($total / $perPage),
        ], 'Vendor payments fetched successfully (from journal)');
    }

    public function ledger(Request $request, Vendor $vendor)
    {
        $page     = max(1, (int)$request->get('page', 1));
        $perPage  = max(1, min(100, (int)$request->get('per_page', 15)));
        $branchId = $request->integer('branch_id');

        // Optional date range (inclusive)
        $from = $request->date('from'); // Carbon|null
        $to   = $request->date('to');   // Carbon|null

        $svc = new LedgerService();

        $data = $svc->getLedger([
            'party_type' => 'vendor',
            'vendor_id' => $vendor->id,
            'from' => $from,
            'to' => $to,
            'page' => $page,
            'per_page' => $perPage
        ]);

        $label = ucfirst($data['party_type']) . ' ledger fetched successfully';
        return ApiResponse::success($data, $label);
    }

    public function storePayment(Request $request, Vendor $vendor, VendorPaymentService $vendorPaymentService)
    {
        $data = $request->validate([
            'branch_id'  => 'nullable|exists:branches,id',
            'paid_at'    => 'nullable|date',
            'method'     => 'required|string|in:cash,bank,card,wallet',
            'amount'     => 'required|numeric|min:0.01',
            'reference'  => 'nullable|string',
            'note'       => 'nullable|string',
            // Optional UI allocations; purely informational
            'allocations' => 'array',
            'allocations.*.purchase_id' => 'required_with:allocations|exists:purchases,id',
            'allocations.*.amount'      => 'required_with:allocations|numeric|min:0.01',
        ]);

        return DB::transaction(function () use ($data, $vendorPaymentService, $vendor) {

            $vpData = [
                'vendor_id'  => $vendor->id,
                'branch_id'  => $data['branch_id'] ?? null,
                'paid_at'    => $data['paid_at'] ?? now()->toDateString(),
                'method'     => $data['method'],
                'amount'     => round($data['amount'], 2),
                'reference'  => $data['reference'] ?? "Payment Send by " . auth()->user()->name,
                'memo'  => $data['reference'] ?? "Payment Send by " . auth()->user()->name,
                'note'       => $data['note'] ?? null,
                'created_by' => auth()->id(),
            ];
            $vp = $vendorPaymentService->create($vpData);

            return response()->json($vp, 201);
        });
    }
}
