<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Http\Response\ApiResponse;
use App\Services\BranchAdminSafetyService;
use App\Services\BranchContextService;
use App\Services\BranchPermissionStateService;
use App\Services\BranchRoleService;
use App\Services\PermissionAuditService;
use App\Services\PermissionDelegationService;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function index(Request $request, BranchRoleService $branchRoles, PermissionDelegationService $delegation)
    {
        $actor = $request->user();
        $query = $branchRoles->scopedQueryForRequest($request)
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim($request->string('search')->toString());
                $query->where('name', 'like', "%{$search}%");
            })
            ->with('permissions:id,name')
            ->orderBy('name');
        $serialize = fn (Role $role) => array_merge(
            $branchRoles->publicRole($role),
            $delegation->roleCapabilities($actor, $role)
        );

        if ($request->boolean('all')) {
            return ApiResponse::success($query->get()->map($serialize)->values());
        }
        $paginator = $query->paginate(max(1, min(200, $request->integer('per_page', 50))));
        $paginator->getCollection()->transform($serialize);
        return ApiResponse::success($paginator);
    }

    public function store(
        RoleRequest $request,
        BranchContextService $branches,
        BranchRoleService $branchRoles,
        PermissionDelegationService $delegation,
        BranchPermissionStateService $permissionState,
        PermissionAuditService $audit
    ) {
        $data = $request->validated();
        $branchId = $branches->requireBranchId($request);
        $guardName = $data['guard_name'] ?? 'web';

        $role = DB::transaction(function () use ($request, $data, $branchId, $guardName, $branchRoles, $delegation, $permissionState, $audit) {
            $branch = $permissionState->lockBranch($branchId);
            $delegation->assertActorStillHas($request->user(), 'manage-roles');
            $branchRoles->assertBranchRoleNameAvailable($request, $data['name'], $guardName);
            $permissionNames = $delegation->authorizedPermissionNames($request->user(), $data['permissions'] ?? [], $guardName);
            $role = Role::create([
                'name' => $branchRoles->internalNameForBranch($data['name'], $branchId),
                'guard_name' => $guardName,
                'branch_id' => $branchId,
            ]);
            $role->syncPermissions($permissionNames);
            $role->load('permissions:id,name');
            $permissionState->bump($branch);
            $audit->record($request, 'role.created', $role, [], $branchRoles->publicRole($role));
            return $role;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return ApiResponse::success(
            array_merge($branchRoles->publicRole($role), $delegation->roleCapabilities($request->user(), $role)),
            null,
            201
        );
    }

    public function show(Request $request, Role $role, BranchRoleService $branchRoles, PermissionDelegationService $delegation)
    {
        $branchRoles->assertRoleBelongsToRequestBranch($request, $role);
        $role->load('permissions:id,name');
        return ApiResponse::success(array_merge(
            $branchRoles->publicRole($role),
            $delegation->roleCapabilities($request->user(), $role)
        ));
    }

    public function update(
        RoleRequest $request,
        Role $role,
        BranchContextService $branches,
        BranchRoleService $branchRoles,
        PermissionDelegationService $delegation,
        BranchAdminSafetyService $adminSafety,
        BranchPermissionStateService $permissionState,
        PermissionAuditService $audit
    ) {
        $branchRoles->assertRoleBelongsToRequestBranch($request, $role);
        $data = $request->validated();
        $branchId = $branches->requireBranchId($request);

        $role = DB::transaction(function () use ($request, $role, $data, $branchId, $branchRoles, $delegation, $adminSafety, $permissionState, $audit) {
            $branch = $permissionState->lockBranch($branchId);
            $delegation->assertActorStillHas($request->user(), 'manage-roles');
            $role->refresh()->load('permissions:id,name');
            $branchRoles->assertRoleBelongsToRequestBranch($request, $role);
            $delegation->assertCanManageRole($request->user(), $role);
            $before = $branchRoles->publicRole($role);
            $guardName = $data['guard_name'] ?? $role->guard_name ?? 'web';
            $permissionNames = null;

            if (array_key_exists('permissions', $data)) {
                $permissionNames = $delegation->authorizedPermissionNames($request->user(), $data['permissions'] ?? [], $guardName);
                $adminSafety->assertRoleChangeRetainsAdmin($branchId, $role, $permissionNames);
            }
            if (array_key_exists('name', $data)) {
                $branchRoles->assertBranchRoleNameAvailable($request, $data['name'], $guardName, (int) $role->id);
                $role->name = $branchRoles->internalNameForBranch($data['name'], $branchId);
            }
            $role->guard_name = $guardName;
            $role->branch_id = $branchId;
            $role->save();
            if ($permissionNames !== null) {
                $role->syncPermissions($permissionNames);
            }
            $role->load('permissions:id,name');
            $permissionState->bump($branch);
            $audit->record($request, 'role.updated', $role, $before, $branchRoles->publicRole($role));
            return $role;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return ApiResponse::success(array_merge(
            $branchRoles->publicRole($role),
            $delegation->roleCapabilities($request->user(), $role)
        ));
    }

    public function destroy(
        Request $request,
        Role $role,
        BranchRoleService $branchRoles,
        PermissionDelegationService $delegation,
        BranchPermissionStateService $permissionState,
        PermissionAuditService $audit,
        BranchContextService $branches
    ) {
        $branchRoles->assertRoleBelongsToRequestBranch($request, $role);
        $branchId = $branches->requireBranchId($request);

        DB::transaction(function () use ($request, $role, $branchId, $branchRoles, $delegation, $permissionState, $audit) {
            $branch = $permissionState->lockBranch($branchId);
            $delegation->assertActorStillHas($request->user(), 'manage-roles');
            $role->refresh()->load('permissions:id,name');
            $branchRoles->assertRoleBelongsToRequestBranch($request, $role);
            $delegation->assertCanManageRole($request->user(), $role);
            if ($role->users()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'role' => ['Role is assigned to users and cannot be deleted.'],
                ]);
            }
            $before = $branchRoles->publicRole($role);
            $audit->record($request, 'role.deleted', $role, $before, []);
            $role->delete();
            $permissionState->bump($branch);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return ApiResponse::success(null, 'Role deleted successfully');
    }

    public function syncPermissions(
        Request $request,
        Role $role,
        BranchRoleService $branchRoles,
        BranchContextService $branches,
        PermissionDelegationService $delegation,
        BranchAdminSafetyService $adminSafety,
        BranchPermissionStateService $permissionState,
        PermissionAuditService $audit
    ) {
        $branchRoles->assertRoleBelongsToRequestBranch($request, $role);
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string'],
        ]);
        $branchId = $branches->requireBranchId($request);

        $role = DB::transaction(function () use ($request, $role, $data, $branchId, $branchRoles, $delegation, $adminSafety, $permissionState, $audit) {
            $branch = $permissionState->lockBranch($branchId);
            $delegation->assertActorStillHas($request->user(), 'manage-roles');
            $role->refresh()->load('permissions:id,name');
            $branchRoles->assertRoleBelongsToRequestBranch($request, $role);
            $delegation->assertCanManageRole($request->user(), $role);
            $before = $branchRoles->publicRole($role);
            $permissionNames = $delegation->authorizedPermissionNames($request->user(), $data['permissions'], $role->guard_name ?? 'web');
            $adminSafety->assertRoleChangeRetainsAdmin($branchId, $role, $permissionNames);
            $role->syncPermissions($permissionNames);
            $role->load('permissions:id,name');
            $permissionState->bump($branch);
            $audit->record($request, 'role.permissions_synced', $role, $before, $branchRoles->publicRole($role));
            return $role;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return ApiResponse::success(array_merge(
            $branchRoles->publicRole($role),
            $delegation->roleCapabilities($request->user(), $role)
        ));
    }

    public function availablePermissions(Request $request, PermissionDelegationService $delegation)
    {
        $guard = $request->string('guard_name')->toString() ?: 'web';
        $search = trim($request->string('search')->toString());
        $allowed = $delegation->delegablePermissions($request->user());
        $query = Permission::query()->where('guard_name', $guard)->whereIn('name', $allowed)->orderBy('name');

        if ($search !== '') {
            $matchingKeys = $allowed->filter(function (string $key) use ($search) {
                $metadata = PermissionCatalog::metadata($key);
                $haystack = implode(' ', [
                    $key,
                    (string) ($metadata['label'] ?? ''),
                    (string) ($metadata['group'] ?? ''),
                    (string) ($metadata['description'] ?? ''),
                ]);
                return str_contains(mb_strtolower($haystack), mb_strtolower($search));
            })->values();
            $query->whereIn('name', $matchingKeys);
        }

        $serialize = fn ($permission) => array_merge($permission->toArray(), PermissionCatalog::metadata($permission->name));
        if ($request->boolean('all')) {
            return ApiResponse::success($query->get(['id', 'name', 'guard_name'])->map($serialize)->values());
        }
        $paginator = $query->paginate(max(1, min(200, $request->integer('per_page', 200))), ['id', 'name', 'guard_name']);
        $paginator->getCollection()->transform($serialize);
        return ApiResponse::success($paginator);
    }
}
