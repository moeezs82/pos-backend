<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Http\Response\ApiResponse;
use App\Services\BranchContextService;
use App\Services\BranchRoleService;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use App\Support\PermissionCatalog;

class RoleController extends Controller
{
    public function index(Request $request, BranchRoleService $branchRoles)
    {
        $q = $branchRoles->scopedQueryForRequest($request)
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = trim($request->string('search')->toString());
                $q->where('name', 'like', "%{$s}%");
            })
            ->with('permissions:id,name')
            ->orderBy('name');

        $perPage = max(1, min(200, $request->integer('per_page', 50)));

        if ($request->boolean('all')) {
            return ApiResponse::success(
                $q->get()->map(fn (Role $role) => $branchRoles->publicRole($role))->values()
            );
        }

        $paginator = $q->paginate($perPage);
        $paginator->getCollection()->transform(fn (Role $role) => $branchRoles->publicRole($role));

        return ApiResponse::success($paginator);
    }

    public function store(RoleRequest $request, BranchContextService $branches, BranchRoleService $branchRoles)
    {
        $data = $request->validated();
        $branchId = $branches->requireBranchId($request);
        $guardName = $data['guard_name'] ?? 'web';

        $branchRoles->assertBranchRoleNameAvailable($request, $data['name'], $guardName);

        $role = Role::create([
            'name' => $branchRoles->internalNameForBranch($data['name'], $branchId),
            'guard_name' => $guardName,
            'branch_id' => $branchId,
        ]);

        if (!empty($data['permissions'])) {
            $perms = Permission::whereIn('name', PermissionCatalog::normalize($data['permissions']))->get();
            $role->syncPermissions($perms);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ApiResponse::success($branchRoles->publicRole($role->load('permissions:id,name')), null, 201);
    }

    public function show(Request $request, Role $role, BranchRoleService $branchRoles)
    {
        $branchRoles->assertRoleBelongsToRequestBranch($request, $role);

        return ApiResponse::success($branchRoles->publicRole($role->load('permissions:id,name')));
    }

    public function update(RoleRequest $request, Role $role, BranchContextService $branches, BranchRoleService $branchRoles)
    {
        $branchRoles->assertRoleBelongsToRequestBranch($request, $role);

        $data = $request->validated();
        $branchId = $branches->requireBranchId($request);
        $guardName = $data['guard_name'] ?? $role->guard_name ?? 'web';

        if (array_key_exists('name', $data)) {
            $branchRoles->assertBranchRoleNameAvailable($request, $data['name'], $guardName, (int) $role->id);
            $role->name = $branchRoles->internalNameForBranch($data['name'], $branchId);
        }

        $role->guard_name = $guardName;
        $role->branch_id = $branchId;
        $role->save();

        if (array_key_exists('permissions', $data)) {
            $perms = Permission::whereIn('name', PermissionCatalog::normalize($data['permissions'] ?? []))->get();
            $role->syncPermissions($perms);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ApiResponse::success($branchRoles->publicRole($role->load('permissions:id,name')));
    }

    public function destroy(Request $request, Role $role, BranchRoleService $branchRoles)
    {
        $branchRoles->assertRoleBelongsToRequestBranch($request, $role);

        if ($role->users()->exists()) {
            return ApiResponse::error('Role is assigned to users and cannot be deleted.', 422);
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ApiResponse::success(null, 'Role deleted successfully');
    }

    public function syncPermissions(Request $request, Role $role, BranchRoleService $branchRoles)
    {
        $branchRoles->assertRoleBelongsToRequestBranch($request, $role);

        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', \Illuminate\Validation\Rule::notIn(\App\Support\ProtectedPermissions::masterOnly())],
        ], [
            'permissions.*.not_in' => 'This permission is reserved for Master Admin and cannot be assigned to a branch role.',
        ]);

        $perms = Permission::whereIn('name', PermissionCatalog::normalize($data['permissions'] ?? []))->get();
        $role->syncPermissions($perms);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ApiResponse::success($branchRoles->publicRole($role->load('permissions:id,name')));
    }

    public function availablePermissions(Request $request)
    {
        $perPage = (int) $request->integer('per_page', 200);
        $guard = $request->string('guard_name')->toString();
        $search = $request->string('search')->toString();
        $all = $request->boolean('all');

        $q = Permission::query()
            // Never offer Master-Admin-only permissions for branch-role editing.
            ->whereNotIn('name', \App\Support\ProtectedPermissions::masterOnly())
            ->when($guard, fn ($q) => $q->where('guard_name', $guard))
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name');

        if ($all) {
            return ApiResponse::success($q->get(['id', 'name', 'guard_name'])->map(function ($permission) {
                return array_merge($permission->toArray(), PermissionCatalog::metadata($permission->name));
            })->values());
        }

        return ApiResponse::success($q->paginate($perPage, ['id', 'name', 'guard_name']));
    }
}
