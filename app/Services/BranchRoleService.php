<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class BranchRoleService
{
    /**
     * Internal branch role format:
     *   admin - Main Branch [branch:1]
     *
     * Frontend/API responses always receive only the public base name:
     *   admin
     */
    public const BRANCH_SUFFIX_PATTERN = '/\s-\s.*\s\[branch:\d+\]$/u';

    public function __construct(private BranchContextService $branches) {}

    public function displayName(string $roleName): string
    {
        return trim((string) preg_replace(self::BRANCH_SUFFIX_PATTERN, '', $roleName));
    }

    public function normalizedDisplayName(string $roleName): string
    {
        return User::normalizeRoleName($this->displayName($roleName));
    }

    public function internalNameForBranch(string $publicName, int|Branch $branch): string
    {
        $branchModel = $branch instanceof Branch
            ? $branch
            : Branch::query()->findOrFail((int) $branch);

        $name = $this->cleanRequestedName($publicName);

        return "{$name} - {$branchModel->name} [branch:{$branchModel->id}]";
    }

    public function cleanRequestedName(string $name): string
    {
        $name = $this->displayName($name);
        $name = trim(preg_replace('/\s+/', ' ', $name) ?: $name);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['Role name is required.'],
            ]);
        }

        return $name;
    }

    public function branchIdColumnExists(): bool
    {
        try {
            return Schema::hasColumn(config('permission.table_names.roles', 'roles'), 'branch_id');
        } catch (\Throwable) {
            return false;
        }
    }

    public function scopedQueryForRequest(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $branchId = $this->branches->requireBranchId($request);

        return Role::query()
            ->when($this->branchIdColumnExists(), fn ($q) => $q->where('branch_id', $branchId))
            ->when(!$this->branchIdColumnExists(), fn ($q) => $q->whereRaw('1 = 0'));
    }

    public function assertRoleBelongsToRequestBranch(Request $request, Role $role): void
    {
        $branchId = $this->branches->requireBranchId($request);

        if (!$this->branchIdColumnExists() || (int) ($role->branch_id ?? 0) !== $branchId) {
            throw ValidationException::withMessages([
                'role_id' => ['Selected role is not available for this branch.'],
            ]);
        }
    }

    public function assertBranchRoleNameAvailable(Request $request, string $name, string $guardName = 'web', ?int $ignoreRoleId = null): void
    {
        $branchId = $this->branches->requireBranchId($request);
        $publicName = $this->cleanRequestedName($name);

        if (User::isMasterAdminRoleName($publicName)) {
            throw ValidationException::withMessages([
                'name' => ['Master admin is a protected system role and cannot be created as a branch role.'],
            ]);
        }

        $normalized = User::normalizeRoleName($publicName);

        $duplicate = Role::query()
            ->where('guard_name', $guardName)
            ->when($this->branchIdColumnExists(), fn ($q) => $q->where('branch_id', $branchId))
            ->when($ignoreRoleId, fn ($q) => $q->whereKeyNot($ignoreRoleId))
            ->get(['id', 'name'])
            ->contains(fn (Role $role) => User::normalizeRoleName($this->displayName($role->name)) === $normalized);

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => ['This role already exists for the active branch.'],
            ]);
        }
    }

    public function roleIdsForBaseName(Request $request, string $roleName, string $guardName = 'web'): array
    {
        $branchId = $this->branches->requireBranchId($request);
        $normalized = User::normalizeRoleName($roleName);

        return Role::query()
            ->where('guard_name', $guardName)
            ->when($this->branchIdColumnExists(), fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'name'])
            ->filter(fn (Role $role) => User::normalizeRoleName($this->displayName($role->name)) === $normalized)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function findBranchRoleByBaseName(Request $request, string $roleName, string $guardName = 'web'): ?Role
    {
        $branchId = $this->branches->requireBranchId($request);
        $normalized = User::normalizeRoleName($roleName);

        return Role::query()
            ->where('guard_name', $guardName)
            ->when($this->branchIdColumnExists(), fn ($q) => $q->where('branch_id', $branchId))
            ->with('permissions:id,name')
            ->get()
            ->first(fn (Role $role) => User::normalizeRoleName($this->displayName($role->name)) === $normalized);
    }

    public function roleInputFromData(array $data): array
    {
        if (array_key_exists('role_id', $data) && $data['role_id'] !== null && $data['role_id'] !== '') {
            return [$data['role_id']];
        }

        if (array_key_exists('role_ids', $data) && is_array($data['role_ids'])) {
            return $data['role_ids'];
        }

        if (array_key_exists('roles', $data) && is_array($data['roles'])) {
            return $data['roles'];
        }

        return [];
    }

    public function roleInputWasProvided(Request $request): bool
    {
        return $request->has('role_id') || $request->has('role_ids') || $request->has('roles');
    }

    public function resolveAssignableRoles(Request $request, array $inputRoles, bool $allowMasterRole = false): EloquentCollection
    {
        $branchId = $this->branches->requireBranchId($request);
        $roles = new EloquentCollection();

        foreach ($inputRoles as $rawRole) {
            $value = is_array($rawRole)
                ? ($rawRole['id'] ?? $rawRole['name'] ?? null)
                : $rawRole;

            if ($value === null || $value === '') {
                continue;
            }

            $role = $this->resolveOneRole($request, $value, $branchId, $allowMasterRole);

            if (!$roles->contains('id', $role->id)) {
                $roles->push($role);
            }
        }

        return $roles;
    }

    private function resolveOneRole(Request $request, mixed $value, int $branchId, bool $allowMasterRole): Role
    {
        if (is_numeric($value)) {
            $role = Role::query()->find((int) $value);

            if (!$role) {
                throw ValidationException::withMessages([
                    'role_id' => ['Selected role does not exist.'],
                ]);
            }

            $displayName = $this->displayName($role->name);

            if (User::isMasterAdminRoleName($displayName)) {
                if (!$allowMasterRole || !$this->branches->isMasterAdmin($request->user())) {
                    throw ValidationException::withMessages([
                        'role_id' => ['You cannot assign the master admin role.'],
                    ]);
                }

                return $role;
            }

            if (!$this->branchIdColumnExists() || (int) ($role->branch_id ?? 0) !== $branchId) {
                throw ValidationException::withMessages([
                    'role_id' => ['Selected role is not available for this branch.'],
                ]);
            }

            return $role;
        }

        $publicName = $this->cleanRequestedName((string) $value);

        if (User::isMasterAdminRoleName($publicName)) {
            if (!$allowMasterRole || !$this->branches->isMasterAdmin($request->user())) {
                throw ValidationException::withMessages([
                    'roles' => ['You cannot assign the master admin role.'],
                ]);
            }

            $role = Role::query()
                ->where('guard_name', 'web')
                ->get()
                ->first(fn (Role $role) => User::isMasterAdminRoleName($this->displayName($role->name)));

            if (!$role) {
                throw ValidationException::withMessages([
                    'roles' => ['Master admin role does not exist.'],
                ]);
            }

            return $role;
        }

        $role = $this->findBranchRoleByBaseName($request, $publicName);

        if (!$role) {
            throw ValidationException::withMessages([
                'roles' => ["Role '{$publicName}' is not available for the active branch."],
            ]);
        }

        return $role;
    }

    public function userHasBaseRole(User $user, string $roleName): bool
    {
        $normalized = User::normalizeRoleName($roleName);
        $roles = $user->relationLoaded('roles') ? $user->roles : $user->roles()->get(['roles.id', 'roles.name']);

        return $roles->contains(fn (Role $role) => User::normalizeRoleName($this->displayName($role->name)) === $normalized);
    }

    public function publicRoleNamesForUser(User $user): Collection
    {
        $roles = $user->relationLoaded('roles') ? $user->roles : $user->roles()->get(['roles.id', 'roles.name']);

        return $roles
            ->map(fn (Role $role) => $this->displayName($role->name))
            ->unique(fn (string $name) => User::normalizeRoleName($name))
            ->values();
    }

    public function publicRole(Role $role, bool $includePermissions = true): array
    {
        $payload = [
            'id' => (int) $role->id,
            'name' => $this->displayName($role->name),
            'guard_name' => $role->guard_name,
        ];

        if ($includePermissions) {
            $permissions = $role->relationLoaded('permissions')
                ? $role->permissions
                : $role->permissions()->get(['permissions.id', 'permissions.name']);

            $payload['permissions'] = $permissions
                ->map(fn ($permission) => [
                    'id' => (int) $permission->id,
                    'name' => $permission->name,
                ])
                ->values()
                ->all();
        }

        return $payload;
    }

    public function publicUser(User $user): array
    {
        $payload = $user->toArray();

        $roles = $user->relationLoaded('roles') ? $user->roles : $user->roles()->get(['roles.id', 'roles.name']);
        $publicRoles = $roles
            ->map(fn (Role $role) => $this->publicRole($role, false))
            ->unique(fn (array $role) => User::normalizeRoleName($role['name']))
            ->values();

        $payload['roles'] = $publicRoles->all();
        $payload['role_names'] = $publicRoles->pluck('name')->values()->all();

        return $payload;
    }
}
