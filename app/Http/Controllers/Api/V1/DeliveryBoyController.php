<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\DeliveryBoyReceived;
use App\Models\Sale;
use App\Models\User;
use App\Services\BranchContextService;
use App\Services\BranchRoleService;
use App\Services\DeliveryBoyCashService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeliveryBoyController extends Controller
{
    /**
     * GET /delivery-boys?search=&page=1&per_page=20&from=&to=&branch_id=
     *
     * Returns delivery users with their cash balance.
     * balance > 0 means the delivery boy still owes money to the shop.
     */
    public function index(Request $request, DeliveryBoyCashService $cashService, BranchContextService $branches, BranchRoleService $branchRoles)
    {
        $perPage = max(1, min(200, $request->integer('per_page', 20)));
        $role = $request->input('role', 'delivery');
        $roleIds = $role ? $branchRoles->roleIdsForBaseName($request, (string) $role) : [];

        $query = User::query()
            ->with('roles:id,name')
            ->when($role, function ($q) use ($roleIds) {
                if (empty($roleIds)) {
                    $q->whereRaw('1 = 0');
                    return;
                }

                $q->whereHas('roles', fn ($rq) => $rq->whereIn('roles.id', $roleIds));
            })
            ->where('branch_id', $branches->effectiveBranchId($request))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = trim((string) $request->input('search'));

                $q->where(function ($qq) use ($search) {
                    $qq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");

                    if ($this->hasColumn('users', 'phone')) {
                        $qq->orWhere('phone', 'like', "%{$search}%");
                    }
                });
            })
            ->orderBy('name');

        $paginator = $query->paginate($perPage);
        $summaries = $cashService->summariesForUsers(
            $paginator->getCollection()->pluck('id'),
            $cashService->filtersFromRequest($request)
        );

        $paginator->getCollection()->transform(function (User $user) use ($summaries, $branchRoles) {
            $summary = $summaries[$user->id] ?? null;

            $user->setAttribute('delivery_cash_summary', $summary);
            $user->setAttribute('balance', (float) ($summary['balance'] ?? 0));

            return $branchRoles->publicUser($user);
        });

        return ApiResponse::success($paginator, 'Delivery boys fetched successfully');
    }

    /**
     * GET /delivery-boys/{id}/cash-summary?from=&to=&branch_id=
     *
     * Returns delivery boy info + orders_total + received_total + balance.
     */
    public function cashSummary(Request $request, int $id, DeliveryBoyCashService $cashService, BranchContextService $branches, BranchRoleService $branchRoles)
    {
        $user = User::query()->with('roles:id,name')->findOrFail($id);
        $this->assertDeliveryBoyBranch($request, $branches, $user, $branches->effectiveBranchId($request));
        $summary = $cashService->summaryForUser($user, $cashService->filtersFromRequest($request));

        return ApiResponse::success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? null,
            'status' => $user->is_active ? 'active' : 'inactive',
            'roles' => $branchRoles->publicRoleNamesForUser($user),
            'orders_count' => $summary['orders_count'],
            'orders_total' => $summary['orders_total'],
            'received_count' => $summary['received_count'],
            'received_total' => $summary['received_total'],
            'balance' => $summary['balance'],
            'last_order_at' => $summary['last_order_at'],
            'last_received_at' => $summary['last_received_at'],
            'branch_id' => $summary['branch_id'],
        ], 'Delivery boy cash summary fetched successfully');
    }

    /**
     * GET /delivery-boys/{id}/orders?page=1&per_page=10&from=&to=&branch_id=
     *
     * Returns paginated delivery orders with customer, branch, paid amount and open amount.
     */
    public function orders(Request $request, int $id, BranchContextService $branches)
    {
        $user = User::query()->findOrFail($id);
        $perPage = max(1, min(200, $request->integer('per_page', 10)));
        $branchId = $branches->effectiveBranchId($request);
        $this->assertDeliveryBoyBranch($request, $branches, $user, $branchId);
        $from = $request->input('from');
        $to = $request->input('to');

        $paidSub = DB::table('receipts')
            ->select('sale_id')
            ->selectRaw('COALESCE(SUM(amount), 0) AS paid_amount')
            ->groupBy('sale_id');

        $customerNameSql = DB::getDriverName() === 'mysql'
            ? "TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS customer_name"
            : "TRIM(COALESCE(c.first_name, '') || ' ' || COALESCE(c.last_name, '')) AS customer_name";

        // $query = Sale::query()
        $query = DB::table('sales')
            ->from('sales as s')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
            ->leftJoinSub($paidSub, 'rp', 'rp.sale_id', '=', 's.id')
            ->when($this->hasColumn('sales', 'deleted_at'), fn ($q) => $q->whereNull('s.deleted_at'))
            ->where('s.delivery_boy_id', $id)
            ->whereNotIn('s.status', ['cancelled'])
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->when($from, fn ($q) => $q->whereDate('s.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('s.created_at', '<=', $to))
            ->select([
                's.id',
                's.invoice_no',
                's.customer_id',
                's.branch_id',
                's.sale_type',
                's.status',
                's.subtotal',
                's.discount',
                's.tax',
                's.delivery',
                's.total',
                's.created_at',
                DB::raw($customerNameSql),
                'c.phone as customer_phone',
                'b.name as branch_name',
                DB::raw('COALESCE(rp.paid_amount, 0) AS paid_amount'),
                DB::raw('(s.total - COALESCE(rp.paid_amount, 0)) AS open_amount'),
            ])
            ->latest('s.id');

        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(fn ($row) => [
            'id' => (int) $row->id,
            'invoice_no' => $row->invoice_no,
            'customer_id' => $row->customer_id ? (int) $row->customer_id : null,
            'customer_name' => $row->customer_name ?: null,
            'customer_phone' => $row->customer_phone,
            'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
            'branch_name' => $row->branch_name,
            'sale_type' => $row->sale_type,
            'status' => $row->status,
            'subtotal' => (float) $row->subtotal,
            'discount' => (float) $row->discount,
            'tax' => (float) $row->tax,
            'delivery' => (float) $row->delivery,
            'total' => (float) $row->total,
            'paid_amount' => (float) $row->paid_amount,
            'open_amount' => (float) $row->open_amount,
            'created_at' => (string) $row->created_at,
        ])->values();

        return ApiResponse::success([
            'items' => $items,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ], 'Delivery boy orders fetched successfully');
    }

    /**
     * GET /delivery-boys/{id}/received?page=1&per_page=10&from=&to=
     *
     * Returns paginated amounts already received from this delivery boy.
     */
    public function received(Request $request, int $id, BranchContextService $branches)
    {
        $user = User::query()->findOrFail($id);
        $perPage = max(1, min(200, $request->integer('per_page', 10)));
        $from = $request->input('from');
        $to = $request->input('to');
        $branchId = $branches->effectiveBranchId($request);
        $this->assertDeliveryBoyBranch($request, $branches, $user, $branchId);

        $query = DeliveryBoyReceived::query()
            ->where('user_id', $id)
            ->when($branchId && $this->hasColumn('delivery_boy_received', 'branch_id'), fn ($q) => $q->where('branch_id', $branchId))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->latest('id');

        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(fn (DeliveryBoyReceived $row) => [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
            'amount' => (float) $row->amount,
            'created_at' => $row->created_at?->toDateTimeString(),
            'updated_at' => $row->updated_at?->toDateTimeString(),
        ])->values();

        return ApiResponse::success([
            'items' => $items,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ], 'Delivery boy received entries fetched successfully');
    }

    /**
     * POST /delivery-boys/{id}/received
     * Body: amount
     */
    public function storeReceived(Request $request, int $id, DeliveryBoyCashService $cashService, BranchContextService $branches)
    {
        $user = User::query()->findOrFail($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'branch_id' => ['nullable', 'exists:branches,id'],
        ]);
        $branchId = $branches->requireBranchId($request);
        $this->assertDeliveryBoyBranch($request, $branches, $user, $branchId);

        $row = DeliveryBoyReceived::query()->create([
            'user_id' => $id,
            'branch_id' => $branchId,
            'amount' => round((float) $data['amount'], 2),
        ]);

        return ApiResponse::success([
            'received' => [
                'id' => (int) $row->id,
                'user_id' => (int) $row->user_id,
                'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
                'amount' => (float) $row->amount,
                'created_at' => $row->created_at?->toDateTimeString(),
            ],
            'cash_summary' => $cashService->summaryForUser($id, $cashService->filtersFromRequest($request)),
        ], 'Received recorded successfully', 201);
    }


    private function assertDeliveryBoyBranch(Request $request, BranchContextService $branches, User $user, ?int $branchId = null): void
    {
        if ($user->branch_id) {
            $branches->assertCanAccessBranch($request, (int) $user->branch_id);

            if ($branchId && (int) $user->branch_id !== (int) $branchId) {
                abort(422, 'Selected delivery boy belongs to a different branch.');
            }
        }
    }
    private function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
