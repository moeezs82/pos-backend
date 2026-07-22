<?php

namespace App\Services;

use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\ProtectedPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Central authority for permission delegation and role assignment.
 *
 * A branch user may delegate only permissions they currently hold. Master
 * Admin bypasses that ceiling, but protected system permissions can still
 * never be written to a branch role.
 */
class PermissionDelegationService
{
    public function __construct(private BranchContextService $branches) {}

    public function isMasterAdmin(?User $user): bool
    {
        return $this->branches->isMasterAdmin($user);
    }

    /** @return Collection<int, string> */
    public function delegablePermissions(User $actor): Collection
    {
        if ($this->isMasterAdmin($actor)) {
            return Permission::query()
                ->whereNotIn('name', ProtectedPermissions::masterOnly())
                ->pluck('name')
                ->map(fn ($name) => (string) $name)
                ->unique()
                ->values();
        }

        $actor->unsetRelation('roles');
        $actor->unsetRelation('permissions');

        return $actor->getAllPermissions()
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->diff(ProtectedPermissions::masterOnly())
            ->unique()
            ->values();
    }

    /**
     * Normalize dependencies, reject unknown/protected keys, then enforce the
     * actor's current delegation ceiling. The returned names are safe to sync.
     *
     * @param  array<int, mixed>  $requested
     * @return array<int, string>
     */
    public function authorizedPermissionNames(User $actor, array $requested, string $guardName = 'web'): array
    {
        $requested = array_values(array_unique(array_map(
            fn ($permission) => trim((string) $permission),
            $requested
        )));
        $requested = array_values(array_filter($requested, fn ($permission) => $permission !== ''));

        $protected = ProtectedPermissions::intersect($requested);
        if ($protected !== []) {
            throw ValidationException::withMessages([
                'permissions' => ['Master-Admin-only permissions cannot be assigned to a branch role: ' . implode(', ', $protected)],
            ]);
        }

        $normalized = PermissionCatalog::normalize($requested);
        $normalizedProtected = ProtectedPermissions::intersect($normalized);
        if ($normalizedProtected !== []) {
            throw ValidationException::withMessages([
                'permissions' => ['A permission dependency is reserved for Master Admin: ' . implode(', ', $normalizedProtected)],
            ]);
        }

        $known = Permission::query()
            ->where('guard_name', $guardName)
            ->whereIn('name', $normalized)
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->values();

        $unknown = collect($normalized)->diff($known)->values();
        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => ['Unknown permission key(s): ' . $unknown->implode(', ')],
            ]);
        }

        if (!$this->isMasterAdmin($actor)) {
            $unauthorized = collect($normalized)->diff($this->delegablePermissions($actor))->values();
            if ($unauthorized->isNotEmpty()) {
                throw new AuthorizationException(
                    'You cannot assign permissions you do not hold: ' . $unauthorized->implode(', ')
                );
            }
        }

        return array_values($normalized);
    }

    public function assertCanAssignRole(User $actor, Role $role): void
    {
        if ($this->isMasterAdmin($actor)) {
            return;
        }

        $excess = $this->rolePermissionNames($role)->diff($this->delegablePermissions($actor))->values();
        if ($excess->isNotEmpty()) {
            throw new AuthorizationException(
                'This role contains permissions you are not authorized to assign.'
            );
        }
    }

    public function assertActorStillHas(User $actor, string $permission): void
    {
        if (!$this->isMasterAdmin($actor) && !$this->delegablePermissions($actor)->contains($permission)) {
            throw new AuthorizationException('Your access changed before this request completed. Please refresh and try again.');
        }
    }

    public function assertCanManageRole(User $actor, Role $role): void
    {
        if ($this->isMasterAdmin($actor)) {
            return;
        }

        if ($this->actorHasRole($actor, $role)) {
            throw new AuthorizationException('You cannot edit a role assigned to your own account.');
        }

        $excess = $this->rolePermissionNames($role)->diff($this->delegablePermissions($actor))->values();
        if ($excess->isNotEmpty()) {
            throw new AuthorizationException('You cannot manage a role that is stronger than your own access level.');
        }
    }

    public function assertCanManageUser(User $actor, User $target): void
    {
        if ($this->isMasterAdmin($actor)) {
            return;
        }

        if ($target->isMasterAdmin()) {
            throw new AuthorizationException('Master Admin accounts cannot be managed by branch users.');
        }

        $target->unsetRelation('roles');
        $target->unsetRelation('permissions');
        $excess = $target->getAllPermissions()
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->diff($this->delegablePermissions($actor))
            ->values();

        if ($excess->isNotEmpty()) {
            throw new AuthorizationException('You cannot manage a user whose access level is stronger than your own.');
        }
    }

    /** @return array{is_assignable: bool, assignment_block_reason: ?string, is_actor_role: bool, can_edit: bool, edit_block_reason: ?string} */
    public function roleCapabilities(User $actor, Role $role): array
    {
        $isMaster = $this->isMasterAdmin($actor);
        $isActorRole = !$isMaster && $this->actorHasRole($actor, $role);
        $isAssignable = $isMaster || $this->rolePermissionNames($role)
            ->diff($this->delegablePermissions($actor))
            ->isEmpty();

        $assignmentReason = $isAssignable
            ? null
            : 'This role includes permissions above your access level.';

        $canEdit = $isMaster || ($isAssignable && !$isActorRole);
        $editReason = null;
        if (!$canEdit) {
            $editReason = $isActorRole
                ? 'You cannot edit the role assigned to your own account.'
                : 'You cannot edit a role that is stronger than your own access level.';
        }

        return [
            'is_assignable' => $isAssignable,
            'assignment_block_reason' => $assignmentReason,
            'is_actor_role' => $isActorRole,
            'can_edit' => $canEdit,
            'edit_block_reason' => $editReason,
        ];
    }

    /** @return array{is_manageable: bool, management_block_reason: ?string, is_current_user: bool} */
    public function userCapabilities(User $actor, User $target): array
    {
        $isCurrent = (int) $actor->id === (int) $target->id;
        if ($this->isMasterAdmin($actor)) {
            return [
                'is_manageable' => true,
                'management_block_reason' => null,
                'is_current_user' => $isCurrent,
            ];
        }

        if ($target->isMasterAdmin()) {
            return [
                'is_manageable' => false,
                'management_block_reason' => 'Master Admin accounts cannot be managed by branch users.',
                'is_current_user' => $isCurrent,
            ];
        }

        $targetPermissions = $target->getAllPermissions()->pluck('name')->map(fn ($name) => (string) $name);
        $manageable = $targetPermissions->diff($this->delegablePermissions($actor))->isEmpty();

        return [
            'is_manageable' => $manageable,
            'management_block_reason' => $manageable
                ? null
                : 'This user has permissions above your access level.',
            'is_current_user' => $isCurrent,
        ];
    }

    private function actorHasRole(User $actor, Role $role): bool
    {
        $roles = $actor->relationLoaded('roles')
            ? $actor->roles
            : $actor->roles()->get(['roles.id', 'roles.name']);

        return $roles->contains(fn (Role $candidate) => (int) $candidate->id === (int) $role->id);
    }

    /** @return Collection<int, string> */
    private function rolePermissionNames(Role $role): Collection
    {
        $permissions = $role->relationLoaded('permissions')
            ? $role->permissions
            : $role->permissions()->get(['permissions.id', 'permissions.name']);

        return $permissions->pluck('name')->map(fn ($name) => (string) $name)->unique()->values();
    }
}
