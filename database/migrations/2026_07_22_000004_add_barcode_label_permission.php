<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the employee-level barcode-label permission and preserves existing
 * access by granting it to roles that already held manage-products. Master
 * Admin can then narrow those assignments through the normal role editor.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = (array) config('permission.table_names', []);
        $permissionsTable = $tables['permissions'] ?? 'permissions';
        $pivotTable = $tables['role_has_permissions'] ?? 'role_has_permissions';

        if (!Schema::hasTable($permissionsTable) || !Schema::hasTable($pivotTable)) {
            return;
        }

        $manageProductsId = DB::table($permissionsTable)
            ->where('name', 'manage-products')
            ->where('guard_name', 'web')
            ->value('id');

        $permissionId = DB::table($permissionsTable)
            ->where('name', 'print-barcode-labels')
            ->where('guard_name', 'web')
            ->value('id');

        if (!$permissionId) {
            $row = [
                'name' => 'print-barcode-labels',
                'guard_name' => 'web',
            ];
            if (Schema::hasColumn($permissionsTable, 'created_at')) {
                $row['created_at'] = now();
            }
            if (Schema::hasColumn($permissionsTable, 'updated_at')) {
                $row['updated_at'] = now();
            }
            $permissionId = DB::table($permissionsTable)->insertGetId($row);
        }

        if ($manageProductsId) {
            $roleIds = DB::table($pivotTable)
                ->where('permission_id', $manageProductsId)
                ->pluck('role_id');

            foreach ($roleIds->chunk(500) as $chunk) {
                DB::table($pivotTable)->insertOrIgnore(
                    $chunk->map(fn ($roleId) => [
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ])->all()
                );
            }
        }

        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'permission_version')) {
            DB::table('branches')->increment('permission_version');
        }

        if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Deliberately retained on rollback: deleting a permission after roles
        // have been edited would destroy authorization choices made in production.
    }
};
