<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Response\ApiResponse;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $page    = max(1, (int)$request->get('page', 1));
        $perPage = max(1, min(500, (int)$request->get('per_page', 15)));
        $search  = trim((string)$request->get('search', ''));
        $branchId = $request->integer('branch_id'); // optional, if you want per-branch AR

        // ---------- Phase 1: get just the IDs for this page (cheap) ----------
        $idQuery = Customer::query()->select('id');

        if ($search !== '') {
            $idQuery->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name',  'like', "%{$search}%")
                    ->orWhere('email',      'like', "%{$search}%")
                    ->orWhere('phone',      'like', "%{$search}%");
            });
        }

        // Light sort that can use a name index; adjust if you prefer created_at
        $idQuery->orderBy('first_name')->orderBy('last_name');

        $total = (clone $idQuery)->count();
        $ids   = (clone $idQuery)
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->pluck('id')
            ->all();

        if (empty($ids)) {
            $data = [
                'customers'     => [],
                'total'         => $total,
                'per_page'      => $perPage,
                'current_page'  => $page,
                'last_page'     => (int)ceil($total / $perPage),
            ];
            return ApiResponse::success($data, 'Customers fetched successfully');
        }

        // ---------- Phase 2: pre-aggregated subqueries (NOT the view) ----------
        // Sales aggregate (optionally filter by branch/status if that’s your rule)
        $salesAgg = DB::table('sales')
            ->select([
                'customer_id',
                DB::raw('SUM(total)        AS tot_sales'),
                DB::raw('MAX(invoice_date) AS last_sale_date'),
            ])
            ->whereIn('customer_id', $ids)
            // ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            // ->where('status', 'posted')
            ->groupBy('customer_id');

        // Receipts: include both allocated and unallocated portions
        // 1) Per-receipt: how much applied to invoices?
        $receiptPer = DB::table('receipts as r')
            ->leftJoin('receipt_allocations as ra', 'ra.receipt_id', '=', 'r.id')
            ->select([
                'r.id',
                'r.customer_id',
                DB::raw('COALESCE(SUM(ra.amount), 0) AS applied_sum'),
                'r.amount',
                'r.received_at',
            ])
            ->whereIn('r.customer_id', $ids)
            ->when($branchId, fn($q) => $q->where('r.branch_id', $branchId))
            // ->where('r.status', 'cleared') // if you track status
            ->groupBy('r.id', 'r.customer_id', 'r.amount', 'r.received_at');

        // 2) Roll up per customer (applied + unallocated)
        $rcAgg = DB::query()
            ->fromSub($receiptPer, 'rp')
            ->select([
                'rp.customer_id',
                DB::raw('SUM(rp.applied_sum) AS tot_applied'),
                DB::raw('SUM(GREATEST(rp.amount - rp.applied_sum, 0)) AS tot_unallocated'),
                DB::raw('MAX(rp.received_at) AS last_receipt_date'),
            ])
            ->groupBy('rp.customer_id');

        // Final page rows
        $rows = Customer::query()
            ->whereIn('customers.id', $ids)
            ->leftJoinSub($salesAgg, 's',  's.customer_id',  '=', 'customers.id')
            ->leftJoinSub($rcAgg,    'rc', 'rc.customer_id', '=', 'customers.id')
            ->select([
                'customers.*',
                DB::raw('COALESCE(s.tot_sales, 0.0) AS total_sales'),
                // compute total_receipts from applied + unallocated
                DB::raw('(COALESCE(rc.tot_applied,0) + COALESCE(rc.tot_unallocated,0)) AS total_receipts'),
                DB::raw('(COALESCE(s.tot_sales, 0.0) - (COALESCE(rc.tot_applied,0) + COALESCE(rc.tot_unallocated,0))) AS remaining_balance'),
                DB::raw("
                CASE
                  WHEN COALESCE(s.last_sale_date,'1970-01-01') >= COALESCE(rc.last_receipt_date,'1970-01-01')
                    THEN COALESCE(s.last_sale_date,'1970-01-01')
                  ELSE COALESCE(rc.last_receipt_date,'1970-01-01')
                END AS last_activity_at
            "),
            ])
            // Preserve original order of IDs (safe: $ids are integers)
            ->orderByRaw('FIELD(customers.id, ' . implode(',', array_map('intval', $ids)) . ')')
            ->get();

        $data = [
            'customers'     => CustomerResource::collection($rows),
            'total'         => $total,
            'per_page'      => $perPage,
            'current_page'  => $page,
            'last_page'     => (int)ceil($total / $perPage),
        ];

        return ApiResponse::success($data, 'Customers fetched successfully');
    }


    public function store(CustomerRequest $request)
    {
        $data = $request->validated();

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $customer = Customer::create($data);
        return ApiResponse::success(new CustomerResource($customer), 'Customer created successfully');
    }

    public function show(Customer $customer)
    {
        return ApiResponse::success(new CustomerResource($customer), 'Customer details fetched successfully');
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
}
