<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $rolesTable = config('permission.table_names.roles') ?: 'roles';
        $modelHasRolesTable = config('permission.table_names.model_has_roles') ?: 'model_has_roles';
        $roleHasPermissionsTable = config('permission.table_names.role_has_permissions') ?: 'role_has_permissions';

        /*
         * Important:
         * In some Spatie versions these config values exist but are null.
         * config('x', 'default') does not apply the default when the key exists as null.
         */
        $rolePivotKey = config('permission.column_names.role_pivot_key') ?: 'role_id';
        $modelMorphKey = config('permission.column_names.model_morph_key') ?: 'model_id';

        if (!Schema::hasTable($rolesTable)) {
            return;
        }

        if (!Schema::hasColumn($rolesTable, 'branch_id')) {
            Schema::table($rolesTable, function (Blueprint $table) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('id');
                $table->index(['branch_id', 'guard_name'], 'roles_branch_guard_idx');
            });
        }

        if (!Schema::hasTable('branches')) {
            return;
        }

        $now = now();

        $branches = DB::table('branches')
            ->select(['id', 'name'])
            ->get();

        $globalRoles = DB::table($rolesTable)
            ->select(['id', 'name', 'guard_name', 'branch_id'])
            ->whereNull('branch_id')
            ->get()
            ->filter(fn ($role) => !$this->isMasterRole((string) $role->name));

        $branchRoleMap = [];

        foreach ($branches as $branch) {
            foreach ($globalRoles as $globalRole) {
                $baseName = $this->baseRoleName((string) $globalRole->name);
                $internalName = $this->internalRoleName($baseName, (string) $branch->name, (int) $branch->id);
                $guardName = (string) ($globalRole->guard_name ?: 'web');

                $existing = DB::table($rolesTable)
                    ->where('name', $internalName)
                    ->where('guard_name', $guardName)
                    ->first();

                if ($existing) {
                    $branchRoleId = (int) $existing->id;

                    if (property_exists($existing, 'branch_id') && !$existing->branch_id) {
                        DB::table($rolesTable)
                            ->where('id', $branchRoleId)
                            ->update([
                                'branch_id' => (int) $branch->id,
                                'updated_at' => $now,
                            ]);
                    }
                } else {
                    $branchRoleId = (int) DB::table($rolesTable)->insertGetId([
                        'name' => $internalName,
                        'guard_name' => $guardName,
                        'branch_id' => (int) $branch->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $branchRoleMap[(int) $branch->id][(int) $globalRole->id] = $branchRoleId;

                if (Schema::hasTable($roleHasPermissionsTable)) {
                    $permissionIds = DB::table($roleHasPermissionsTable)
                        ->where($rolePivotKey, (int) $globalRole->id)
                        ->pluck('permission_id')
                        ->map(fn ($id) => (int) $id)
                        ->all();

                    foreach ($permissionIds as $permissionId) {
                        $exists = DB::table($roleHasPermissionsTable)
                            ->where($rolePivotKey, $branchRoleId)
                            ->where('permission_id', $permissionId)
                            ->exists();

                        if (!$exists) {
                            DB::table($roleHasPermissionsTable)->insert([
                                'permission_id' => $permissionId,
                                $rolePivotKey => $branchRoleId,
                            ]);
                        }
                    }
                }
            }
        }

        if (
            Schema::hasTable('users')
            && Schema::hasTable($modelHasRolesTable)
            && Schema::hasColumn($modelHasRolesTable, $rolePivotKey)
            && Schema::hasColumn($modelHasRolesTable, $modelMorphKey)
        ) {
            $assignments = DB::table($modelHasRolesTable . ' as mhr')
                ->join('users as u', 'u.id', '=', 'mhr.' . $modelMorphKey)
                ->join($rolesTable . ' as r', 'r.id', '=', 'mhr.' . $rolePivotKey)
                ->whereNotNull('u.branch_id')
                ->whereNull('r.branch_id')
                ->select([
                    'mhr.' . $rolePivotKey . ' as old_role_id',
                    'mhr.model_type',
                    'mhr.' . $modelMorphKey . ' as model_id',
                    'u.branch_id',
                    'r.name as old_role_name',
                ])
                ->get();

            foreach ($assignments as $assignment) {
                if ($this->isMasterRole((string) $assignment->old_role_name)) {
                    continue;
                }

                $branchId = (int) $assignment->branch_id;
                $oldRoleId = (int) $assignment->old_role_id;
                $newRoleId = $branchRoleMap[$branchId][$oldRoleId] ?? null;

                if (!$newRoleId) {
                    continue;
                }

                $exists = DB::table($modelHasRolesTable)
                    ->where($rolePivotKey, $newRoleId)
                    ->where('model_type', $assignment->model_type)
                    ->where($modelMorphKey, (int) $assignment->model_id)
                    ->exists();

                if (!$exists) {
                    DB::table($modelHasRolesTable)->insert([
                        $rolePivotKey => $newRoleId,
                        'model_type' => $assignment->model_type,
                        $modelMorphKey => (int) $assignment->model_id,
                    ]);
                }

                DB::table($modelHasRolesTable)
                    ->where($rolePivotKey, $oldRoleId)
                    ->where('model_type', $assignment->model_type)
                    ->where($modelMorphKey, (int) $assignment->model_id)
                    ->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $rolesTable = config('permission.table_names.roles') ?: 'roles';

        if (Schema::hasTable($rolesTable) && Schema::hasColumn($rolesTable, 'branch_id')) {
            Schema::table($rolesTable, function (Blueprint $table) {
                try {
                    $table->dropIndex('roles_branch_guard_idx');
                } catch (Throwable) {
                    //
                }

                $table->dropColumn('branch_id');
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function internalRoleName(string $baseName, string $branchName, int $branchId): string
    {
        return trim($baseName) . ' - ' . trim($branchName) . ' [branch:' . $branchId . ']';
    }

    private function baseRoleName(string $name): string
    {
        return trim((string) preg_replace('/\s-\s.*\s\[branch:\d+\]$/u', '', $name));
    }

    private function normalizeRoleName(string $name): string
    {
        $name = $this->baseRoleName($name);
        $name = str_replace(['_', '-'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?: $name;

        return trim(strtolower($name));
    }

    private function isMasterRole(string $name): bool
    {
        $aliases = defined(User::class . '::MASTER_ROLE_ALIASES')
            ? User::MASTER_ROLE_ALIASES
            : ['master admin', 'master_admin', 'master-admin', 'super admin', 'super_admin', 'super-admin'];

        return in_array($this->normalizeRoleName($name), array_map(
            fn ($role) => $this->normalizeRoleName((string) $role),
            $aliases
        ), true);
    }
};