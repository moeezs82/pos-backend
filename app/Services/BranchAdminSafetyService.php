<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/** Prevents user/role mutations from removing the last complete branch admin. */
class BranchAdminSafetyService
{
    private const REQUIRED = ['manage-users', 'manage-roles'];

    /**
     * Check a prospective user activation/role change. Call while the branch
     * row is locked inside the same transaction as the mutation.
     *
     * @param  Collection<int, Role>  $rolesAfter
     */
    public function assertUserChangeRetainsAdmin(
        int $branchId,
        User $target,
        bool $activeAfter,
        Collection $rolesAfter
    ): void {
        $users = $this->activeUsers($branchId);
        $currentTarget = $users->firstWhere('id', $target->id);

        if (!$currentTarget || !$this->hasRequired($this->permissionNamesForUser($currentTarget))) {
            return;
        }

        $afterPermissions = $target->permissions()
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->merge($rolesAfter->flatMap(function (Role $role) {
                $permissions = $role->relationLoaded('permissions')
                    ? $role->permissions
                    : $role->permissions()->get(['permissions.id', 'permissions.name']);
                return $permissions->pluck('name');
            }))
            ->unique()
            ->values();

        if ($activeAfter && $this->hasRequired($afterPermissions)) {
            return;
        }

        $anotherAdminExists = $users
            ->reject(fn (User $user) => (int) $user->id === (int) $target->id)
            ->contains(fn (User $user) => $this->hasRequired($this->permissionNamesForUser($user)));

        if (!$anotherAdminExists) {
            throw ValidationException::withMessages([
                'user' => ['This change would remove the last active branch administrator. Keep at least one active user with both Manage Users and Manage Roles.'],
            ]);
        }
    }

    /**
     * Check a prospective role permission change, including every active user
     * sharing that role. Call while the branch row is locked.
     *
     * @param  array<int, string>  $permissionsAfter
     */
    public function assertRoleChangeRetainsAdmin(int $branchId, Role $targetRole, array $permissionsAfter): void
    {
        $users = $this->activeUsers($branchId);
        $affectedCurrentAdmin = $users->contains(function (User $user) use ($targetRole) {
            return $user->roles->contains('id', $targetRole->id)
                && $this->hasRequired($this->permissionNamesForUser($user));
        });

        if (!$affectedCurrentAdmin) {
            return;
        }

        $adminRemains = $users->contains(function (User $user) use ($targetRole, $permissionsAfter) {
            $permissions = $user->permissions->pluck('name')->map(fn ($name) => (string) $name);

            foreach ($user->roles as $role) {
                $rolePermissions = (int) $role->id === (int) $targetRole->id
                    ? collect($permissionsAfter)
                    : $role->permissions->pluck('name');
                $permissions = $permissions->merge($rolePermissions);
            }

            return $this->hasRequired($permissions->unique()->values());
        });

        if (!$adminRemains) {
            throw ValidationException::withMessages([
                'permissions' => ['This change would remove the last active branch administrator. Keep at least one active user with both Manage Users and Manage Roles.'],
            ]);
        }
    }

    /** @return Collection<int, User> */
    private function activeUsers(int $branchId): Collection
    {
        return User::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->with(['roles.permissions:id,name', 'permissions:id,name'])
            ->get();
    }

    /** @return Collection<int, string> */
    private function permissionNamesForUser(User $user): Collection
    {
        return $user->permissions->pluck('name')
            ->merge($user->roles->flatMap(fn (Role $role) => $role->permissions->pluck('name')))
            ->map(fn ($name) => (string) $name)
            ->unique()
            ->values();
    }

    /** @param Collection<int, string> $permissions */
    private function hasRequired(Collection $permissions): bool
    {
        return collect(self::REQUIRED)->diff($permissions)->isEmpty();
    }
}
