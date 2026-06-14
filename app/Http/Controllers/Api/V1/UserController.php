<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Response\ApiResponse;
use App\Models\User;
use App\Services\BranchContextService;
use App\Services\BranchRoleService;
use App\Services\DeliveryBoyCashService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    public function index(
        Request $request,
        DeliveryBoyCashService $deliveryCashService,
        BranchContextService $branches,
        BranchRoleService $branchRoles
    ) {
        $roleName = $request->filled('role')
            ? $request->string('role')->toString()
            : null;

        $q = User::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search')->toString();
                $q->where(function ($qq) use ($s) {
                    $qq->where('name', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%");

                    if (Schema::hasColumn('users', 'phone')) {
                        $qq->orWhere('phone', 'like', "%{$s}%");
                    }
                });
            })
            ->when($roleName, function ($q) use ($request, $branchRoles, $roleName) {
                $roleIds = $branchRoles->roleIdsForBaseName($request, $roleName);

                if (empty($roleIds)) {
                    $q->whereRaw('1 = 0');
                    return;
                }

                $q->whereHas('roles', fn ($rq) => $rq->whereIn('roles.id', $roleIds));
            });

        $branches->applyToQuery($q, $request, 'branch_id');

        $q->with(['roles:id,name', 'permissions:id,name']);

        $paginator = $q->paginate($request->integer('per_page', 20));

        $normalizedRole = User::normalizeRoleName((string) $roleName);
        $includeDeliveryBalance = $request->boolean('include_delivery_balance')
            || $request->boolean('include_balance')
            || str_contains($normalizedRole, 'delivery');

        if ($includeDeliveryBalance) {
            $summaries = $deliveryCashService->summariesForUsers(
                $paginator->getCollection()->pluck('id'),
                $deliveryCashService->filtersFromRequest($request)
            );

            $paginator->getCollection()->transform(function (User $user) use ($summaries) {
                $summary = $summaries[$user->id] ?? [
                    'orders_count' => 0,
                    'orders_total' => 0.0,
                    'received_count' => 0,
                    'received_total' => 0.0,
                    'balance' => 0.0,
                    'last_order_at' => null,
                    'last_received_at' => null,
                    'branch_id' => null,
                ];

                $user->setAttribute('delivery_cash_summary', $summary);
                $user->setAttribute('balance', (float) $summary['balance']);

                return $user;
            });
        }

        $paginator->getCollection()->transform(fn (User $user) => $branchRoles->publicUser($user));

        return ApiResponse::success($paginator);
    }

    public function store(UserRequest $request, BranchContextService $branches, BranchRoleService $branchRoles)
    {
        $data = $request->validated();
        $branchId = $branches->requireBranchId($request);
        $allowMasterRole = $branches->isMasterAdmin($request->user());
        $roles = $branchRoles->resolveAssignableRoles(
            $request,
            $branchRoles->roleInputFromData($data),
            $allowMasterRole
        );

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'branch_id' => $branchId,
            'is_active' => $data['is_active'] ?? true,
        ]);

        if ($roles->isNotEmpty()) {
            $user->syncRoles($roles);
        }

        $user->load('roles:id,name');

        return ApiResponse::success($branchRoles->publicUser($user), null, 201);
    }

    public function show(Request $request, User $user, BranchContextService $branches, BranchRoleService $branchRoles)
    {
        $this->assertUserVisibleInActiveBranch($request, $user, $branches);

        return ApiResponse::success($branchRoles->publicUser($user->load('roles:id,name')));
    }

    public function update(UserRequest $request, User $user, BranchContextService $branches, BranchRoleService $branchRoles)
    {
        $this->assertUserVisibleInActiveBranch($request, $user, $branches);

        $data = $request->validated();
        $branchId = $branches->requireBranchId($request);
        $rolesProvided = $branchRoles->roleInputWasProvided($request);
        $roles = $rolesProvided
            ? $branchRoles->resolveAssignableRoles($request, $branchRoles->roleInputFromData($data), $branches->isMasterAdmin($request->user()))
            : null;

        $payload = [
            'name' => $data['name'] ?? $user->name,
            'email' => $data['email'] ?? $user->email,
            'phone' => $data['phone'] ?? $user->phone,
            'branch_id' => $branchId,
            'is_active' => $data['is_active'] ?? $user->is_active,
        ];

        if (!empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        $user->update($payload);

        if ($roles !== null) {
            $user->syncRoles($roles);
        }

        $user->load('roles:id,name');

        return ApiResponse::success($branchRoles->publicUser($user), null, 200);
    }

    public function destroy(Request $request, User $user, BranchContextService $branches)
    {
        $this->assertUserVisibleInActiveBranch($request, $user, $branches);

        $user->tokens()->delete();
        $user->delete();

        return ApiResponse::success(null, 'User deleted successfully');
    }

    public function syncRoles(Request $request, User $user, BranchContextService $branches, BranchRoleService $branchRoles)
    {
        $this->assertUserVisibleInActiveBranch($request, $user, $branches);

        $data = $request->validate([
            'role_id' => ['sometimes', 'nullable'],
            'role_ids' => ['sometimes', 'array', 'min:1', 'max:1'],
            'role_ids.*' => ['required'],
            'roles' => ['sometimes', 'array', 'min:1', 'max:1'],
            'roles.*' => ['required'],
        ]);

        $roles = $branchRoles->resolveAssignableRoles(
            $request,
            $branchRoles->roleInputFromData($data),
            $branches->isMasterAdmin($request->user())
        );

        $user->syncRoles($roles);
        $user->load('roles:id,name');

        return ApiResponse::success($branchRoles->publicUser($user));
    }

    public function deliveryBoyCashSummary(Request $request, User $user)
    {
        $from = $request->date('from');
        $to = $request->date('to');

        $ordersQuery = $user->deliveryOrders()
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));

        $receivedQuery = $user->deliveryBoyReceived()
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));

        $ordersTotal = $ordersQuery->sum('total');
        $receivedTotal = $receivedQuery->sum('amount');

        return ApiResponse::success([
            'delivery_boy' => ['id' => $user->id, 'name' => $user->name],
            'filters' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'orders_total' => (float) $ordersTotal,
            'received_total' => (float) $receivedTotal,
            'balance' => (float) ($ordersTotal - $receivedTotal),
            'orders' => $ordersQuery->latest()->paginate($request->integer('orders_per_page', 20)),
            'received' => $receivedQuery->latest()->paginate($request->integer('received_per_page', 20)),
        ]);
    }

    private function assertUserVisibleInActiveBranch(Request $request, User $user, BranchContextService $branches): void
    {
        $branchId = $branches->requireBranchId($request);

        if ((int) ($user->branch_id ?? 0) !== $branchId) {
            abort(403, 'You do not have access to this user.');
        }
    }
}
