<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Response\ApiResponse;
use App\Models\User;
use App\Services\BranchAdminSafetyService;
use App\Services\BranchContextService;
use App\Services\BranchPermissionStateService;
use App\Services\BranchRoleService;
use App\Services\DeliveryBoyCashService;
use App\Services\PermissionAuditService;
use App\Services\PermissionDelegationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    public function index(
        Request $request,
        DeliveryBoyCashService $deliveryCashService,
        BranchContextService $branches,
        BranchRoleService $branchRoles,
        PermissionDelegationService $delegation
    ) {
        $roleName = $request->filled('role')
            ? $request->string('role')->toString()
            : null;

        $query = User::query()
            ->whereDoesntHave('roles', fn ($roleQuery) => $roleQuery->whereIn('roles.name', User::MASTER_ROLE_ALIASES))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(function ($nested) use ($search) {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");

                    if (Schema::hasColumn('users', 'phone')) {
                        $nested->orWhere('phone', 'like', "%{$search}%");
                    }
                });
            })
            ->when($roleName, function ($query) use ($request, $branchRoles, $roleName) {
                $roleIds = $branchRoles->roleIdsForBaseName($request, $roleName);
                empty($roleIds)
                    ? $query->whereRaw('1 = 0')
                    : $query->whereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('roles.id', $roleIds));
            });

        $branches->applyToQuery($query, $request, 'branch_id');
        $query->with(['roles.permissions:id,name', 'permissions:id,name']);

        $paginator = $query->paginate(max(1, min(200, $request->integer('per_page', 20))));

        $normalizedRole = User::normalizeRoleName((string) $roleName);
        $includeDeliveryBalance = $request->boolean('include_delivery_balance')
            || $request->boolean('include_balance')
            || str_contains($normalizedRole, 'delivery');

        $summaries = $includeDeliveryBalance
            ? $deliveryCashService->summariesForUsers(
                $paginator->getCollection()->pluck('id'),
                $deliveryCashService->filtersFromRequest($request)
            )
            : collect();

        $paginator->getCollection()->transform(function (User $user) use (
            $request,
            $branchRoles,
            $delegation,
            $includeDeliveryBalance,
            $summaries
        ) {
            if ($includeDeliveryBalance) {
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
            }

            return array_merge(
                $branchRoles->publicUser($user),
                $delegation->userCapabilities($request->user(), $user)
            );
        });

        return ApiResponse::success($paginator);
    }

    public function store(
        UserRequest $request,
        BranchContextService $branches,
        BranchRoleService $branchRoles,
        PermissionDelegationService $delegation,
        BranchPermissionStateService $permissionState,
        PermissionAuditService $audit
    ) {
        $data = $request->validated();
        $branchId = $branches->requireBranchId($request);

        $user = DB::transaction(function () use (
            $request,
            $data,
            $branchId,
            $branches,
            $branchRoles,
            $delegation,
            $permissionState,
            $audit
        ) {
            $branch = $permissionState->lockBranch($branchId);
            $delegation->assertActorStillHas($request->user(), 'manage-users');

            $roles = $branchRoles->resolveAssignableRoles(
                $request,
                $branchRoles->roleInputFromData($data),
                $branches->isMasterAdmin($request->user())
            );

            $user = User::query()->create([
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

            $user->load(['roles.permissions:id,name', 'permissions:id,name']);
            $audit->record($request, 'user.created', $user, [], $this->userSnapshot($user, $branchRoles));
            $permissionState->bump($branch);

            return $user;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ApiResponse::success($branchRoles->publicUser($user), null, 201);
    }

    public function show(
        Request $request,
        User $user,
        BranchContextService $branches,
        BranchRoleService $branchRoles,
        PermissionDelegationService $delegation
    ) {
        $this->assertUserVisibleInActiveBranch($request, $user, $branches);
        $user->load(['roles.permissions:id,name', 'permissions:id,name']);

        return ApiResponse::success(array_merge(
            $branchRoles->publicUser($user),
            $delegation->userCapabilities($request->user(), $user)
        ));
    }

    public function update(
        UserRequest $request,
        User $user,
        BranchContextService $branches,
        BranchRoleService $branchRoles,
        PermissionDelegationService $delegation,
        BranchAdminSafetyService $adminSafety,
        BranchPermissionStateService $permissionState,
        PermissionAuditService $audit
    ) {
        $data = $request->validated();
        $branchId = $branches->requireBranchId($request);
        $rolesProvided = $branchRoles->roleInputWasProvided($request);

        $updatedUser = DB::transaction(function () use (
            $request,
            $user,
            $data,
            $branchId,
            $rolesProvided,
            $branches,
            $branchRoles,
            $delegation,
            $adminSafety,
            $permissionState,
            $audit
        ) {
            $branch = $permissionState->lockBranch($branchId);
            $delegation->assertActorStillHas($request->user(), 'manage-users');

            $user = User::query()
                ->with(['roles.permissions:id,name', 'permissions:id,name'])
                ->lockForUpdate()
                ->findOrFail($user->id);
            $this->assertUserVisibleInActiveBranch($request, $user, $branches);
            $delegation->assertCanManageUser($request->user(), $user);

            if ($rolesProvided
                && !$branches->isMasterAdmin($request->user())
                && (int) $request->user()->id === (int) $user->id) {
                throw new AuthorizationException('You cannot change the role assigned to your own account.');
            }

            $rolesAfter = $rolesProvided
                ? $branchRoles->resolveAssignableRoles(
                    $request,
                    $branchRoles->roleInputFromData($data),
                    $branches->isMasterAdmin($request->user())
                )
                : $user->roles;
            $activeAfter = array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : (bool) $user->is_active;

            if ($rolesProvided || $activeAfter !== (bool) $user->is_active) {
                $adminSafety->assertUserChangeRetainsAdmin($branchId, $user, $activeAfter, $rolesAfter);
            }

            $before = $this->userSnapshot($user, $branchRoles);
            $payload = [
                'name' => $data['name'] ?? $user->name,
                'email' => $data['email'] ?? $user->email,
                'phone' => $data['phone'] ?? $user->phone,
                'branch_id' => $branchId,
                'is_active' => $activeAfter,
            ];

            if (!empty($data['password'])) {
                $payload['password'] = Hash::make($data['password']);
            }

            $user->update($payload);
            if ($rolesProvided) {
                $user->syncRoles($rolesAfter);
            }

            $user->load(['roles.permissions:id,name', 'permissions:id,name']);
            $audit->record($request, 'user.updated', $user, $before, $this->userSnapshot($user, $branchRoles));

            if ($rolesProvided || $activeAfter !== (bool) ($before['is_active'] ?? false)) {
                $permissionState->bump($branch);
            }

            return $user;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ApiResponse::success($branchRoles->publicUser($updatedUser));
    }

    public function destroy(
        Request $request,
        User $user,
        BranchContextService $branches,
        BranchRoleService $branchRoles,
        PermissionDelegationService $delegation,
        BranchAdminSafetyService $adminSafety,
        BranchPermissionStateService $permissionState,
        PermissionAuditService $audit
    ) {
        $branchId = $branches->requireBranchId($request);

        DB::transaction(function () use (
            $request,
            $user,
            $branchId,
            $branches,
            $branchRoles,
            $delegation,
            $adminSafety,
            $permissionState,
            $audit
        ) {
            $branch = $permissionState->lockBranch($branchId);
            $delegation->assertActorStillHas($request->user(), 'manage-users');

            $user = User::query()
                ->with(['roles.permissions:id,name', 'permissions:id,name'])
                ->lockForUpdate()
                ->findOrFail($user->id);
            $this->assertUserVisibleInActiveBranch($request, $user, $branches);
            $delegation->assertCanManageUser($request->user(), $user);

            if (!$branches->isMasterAdmin($request->user())
                && (int) $request->user()->id === (int) $user->id) {
                throw new AuthorizationException('You cannot delete your own user account.');
            }

            $adminSafety->assertUserChangeRetainsAdmin($branchId, $user, false, collect());
            $before = $this->userSnapshot($user, $branchRoles);
            $audit->record($request, 'user.deleted', $user, $before, []);

            $user->tokens()->delete();
            $user->delete();
            $permissionState->bump($branch);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ApiResponse::success(null, 'User deleted successfully');
    }

    public function syncRoles(
        Request $request,
        User $user,
        BranchContextService $branches,
        BranchRoleService $branchRoles,
        PermissionDelegationService $delegation,
        BranchAdminSafetyService $adminSafety,
        BranchPermissionStateService $permissionState,
        PermissionAuditService $audit
    ) {
        $data = $request->validate([
            'role_id' => ['sometimes', 'nullable'],
            'role_ids' => ['sometimes', 'array', 'min:1', 'max:1'],
            'role_ids.*' => ['required'],
            'roles' => ['sometimes', 'array', 'min:1', 'max:1'],
            'roles.*' => ['required'],
        ]);
        $branchId = $branches->requireBranchId($request);

        $updatedUser = DB::transaction(function () use (
            $request,
            $user,
            $data,
            $branchId,
            $branches,
            $branchRoles,
            $delegation,
            $adminSafety,
            $permissionState,
            $audit
        ) {
            $branch = $permissionState->lockBranch($branchId);
            $delegation->assertActorStillHas($request->user(), 'manage-users');

            $user = User::query()
                ->with(['roles.permissions:id,name', 'permissions:id,name'])
                ->lockForUpdate()
                ->findOrFail($user->id);
            $this->assertUserVisibleInActiveBranch($request, $user, $branches);
            $delegation->assertCanManageUser($request->user(), $user);

            if (!$branches->isMasterAdmin($request->user())
                && (int) $request->user()->id === (int) $user->id) {
                throw new AuthorizationException('You cannot change the role assigned to your own account.');
            }

            $roles = $branchRoles->resolveAssignableRoles(
                $request,
                $branchRoles->roleInputFromData($data),
                $branches->isMasterAdmin($request->user())
            );
            $adminSafety->assertUserChangeRetainsAdmin($branchId, $user, (bool) $user->is_active, $roles);

            $before = $this->userSnapshot($user, $branchRoles);
            $user->syncRoles($roles);
            $user->load(['roles.permissions:id,name', 'permissions:id,name']);

            $audit->record($request, 'user.roles_synced', $user, $before, $this->userSnapshot($user, $branchRoles));
            $permissionState->bump($branch);

            return $user;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ApiResponse::success($branchRoles->publicUser($updatedUser));
    }

    public function deliveryBoyCashSummary(Request $request, User $user, DeliveryBoyCashService $deliveryCashService)
    {
        $from = $request->date('from');
        $to = $request->date('to');
        $summary = $deliveryCashService->summaryForUser($user, $deliveryCashService->filtersFromRequest($request));

        return ApiResponse::success([
            'delivery_boy' => ['id' => $user->id, 'name' => $user->name],
            'filters' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'orders_count' => $summary['orders_count'],
            'orders_total' => $summary['orders_total'],
            'received_count' => $summary['received_count'],
            'received_total' => $summary['received_total'],
            'balance' => $summary['balance'],
            'last_order_at' => $summary['last_order_at'],
            'last_received_at' => $summary['last_received_at'],
            'orders' => $user->deliveryOrders()
                ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
                ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
                ->latest()
                ->paginate($request->integer('orders_per_page', 20)),
            'received' => $user->deliveryBoyReceived()
                ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
                ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
                ->latest()
                ->paginate($request->integer('received_per_page', 20)),
        ]);
    }

    private function assertUserVisibleInActiveBranch(Request $request, User $user, BranchContextService $branches): void
    {
        $branchId = $branches->requireBranchId($request);

        if ((int) ($user->branch_id ?? 0) !== $branchId) {
            throw new AuthorizationException('You do not have access to this user.');
        }
    }

    private function userSnapshot(User $user, BranchRoleService $branchRoles): array
    {
        $user->loadMissing(['roles.permissions:id,name', 'permissions:id,name']);

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'branch_id' => $user->branch_id ? (int) $user->branch_id : null,
            'is_active' => (bool) $user->is_active,
            'roles' => $branchRoles->publicRoleNamesForUser($user)->sort()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
        ];
    }
}
