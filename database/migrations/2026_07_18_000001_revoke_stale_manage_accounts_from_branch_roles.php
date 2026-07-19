<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production-safe revocation of a stale `manage-accounts` permission from every
 * NON-Master-Admin role. Chart-of-Accounts administration is now Master-Admin
 * only; a seeder change alone can't fix databases where branch Admin / custom
 * branch roles already hold the permission.
 *
 * Idempotent, and safe if the permission / roles / tables are missing during an
 * unusual install or rollback state. The permission itself is NOT deleted —
 * Master Admin still uses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = (array) config('permission.table_names', []);
        $rolesTable = $tables['roles'] ?? 'roles';
        $permsTable = $tables['permissions'] ?? 'permissions';
        $pivotTable = $tables['role_has_permissions'] ?? 'role_has_permissions';

        if (!Schema::hasTable($rolesTable) || !Schema::hasTable($permsTable) || !Schema::hasTable($pivotTable)) {
            return;
        }

        $permId = DB::table($permsTable)->where('name', 'manage-accounts')->value('id');
        if (!$permId) {
            return; // Nothing to revoke.
        }

        // Preserve the permission on the canonical global Master Admin role(s).
        $masterAliases = ['master admin', 'master_admin', 'master-admin'];
        $masterRoleIds = DB::table($rolesTable)
            ->whereIn(DB::raw('LOWER(name)'), $masterAliases)
            ->pluck('id')
            ->all();

        $query = DB::table($pivotTable)->where('permission_id', $permId);
        if (!empty($masterRoleIds)) {
            $query->whereNotIn('role_id', $masterRoleIds);
        }
        $query->delete();

        // Flush Spatie's permission cache so the change takes effect immediately.
        if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: we cannot truthfully know which roles previously
        // held `manage-accounts`, and broadly re-granting an owner-only
        // permission would invent authorization state. See migration docblock.
    }
};
