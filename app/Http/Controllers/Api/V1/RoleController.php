<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Http\Response\ApiResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $q = Role::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search')->toString();
                $q->where('name', 'like', "%{$s}%");
            })
            ->with('permissions:id,name');

        return ApiResponse::success($q->paginate($request->integer('per_page', 50)));
    }

    public function store(RoleRequest $request)
    {
        $data = $request->validated();

        $role = Role::create([
            'name'       => $data['name'],
            'guard_name' => $data['guard_name'] ?? 'web',
        ]);

        if (!empty($data['permissions'])) {
            $perms = Permission::whereIn('name', $data['permissions'])->get();
            $role->syncPermissions($perms);
        }

        return ApiResponse::success($role->load('permissions:id,name'), null, 201);
    }

    public function show(Role $role)
    {
        return ApiResponse::success($role->load('permissions:id,name'));
    }

    public function update(RoleRequest $request, Role $role)
    {
        $data = $request->validated();

        $role->update([
            'name'       => $data['name']       ?? $role->name,
            'guard_name' => $data['guard_name'] ?? 'web',
        ]);

        if (array_key_exists('permissions', $data)) {
            $perms = Permission::whereIn('name', $data['permissions'] ?? [])->get();
            $role->syncPermissions($perms);
        }

        return ApiResponse::success($role->load('permissions:id,name'));
    }

    public function destroy(Role $role)
    {
        if ($role->users()->exists()) {
            return ApiResponse::error("Role is assigned to users and cannot be deleted.", 422);
        }
        $role->delete();
        return ApiResponse::success(null, 'Role deleted successfully');
    }

    // Sync permissions to a role (permissions must already exist; no Permission CRUD)
    public function syncPermissions(Request $request, Role $role)
    {
        $data = $request->validate(['permissions' => ['array'], 'permissions.*' => ['string']]);
        $perms = Permission::whereIn('name', $data['permissions'] ?? [])->get();
        $role->syncPermissions($perms);
        return ApiResponse::success($role->load('permissions:id,name'));
    }

    public function availablePermissions(Request $request)
    {
        $perPage = (int) $request->integer('per_page', 200);
        $guard   = $request->string('guard_name')->toString();
        $search  = $request->string('search')->toString();
        $all     = $request->boolean('all');

        $q = Permission::query()
            ->when($guard,  fn($q) => $q->where('guard_name', $guard))
            ->when($search, fn($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name');

        if ($all) {
            return \App\Http\Response\ApiResponse::success($q->get(['id', 'name', 'guard_name']));
        }

        return \App\Http\Response\ApiResponse::success($q->paginate($perPage, ['id', 'name', 'guard_name']));
    }
}
