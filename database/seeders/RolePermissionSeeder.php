<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Services\BranchRoleService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use App\Models\Register;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = collect([
            'view-reports',
            'view-users',
            'manage-users',
            'view-stock',
            'adjust-stock',
            'manage-branches',
            'view-customers',
            'manage-customers',
            'view-vendors',
            'manage-vendors',
            'view-products',
            'manage-products',
            'print-barcode-labels',
            'view-categories',
            'manage-categories',
            'view-brands',
            'manage-brands',
            'create-sales',
            'manage-sales',
            'refund-sale',
            'view-sales',
            'manage-purchases',
            'view-purchases',
            'view-branches',
            'view-cashbook',
            'manage-cashbook',
            'manage-accounts',
            'view-roles',
            'manage-roles',
            'manage-receipts',
            'manage-payments',
            'view-register-shifts',
            'open-register-shift',
            'close-own-register-shift',
            'manage-register-shifts',
            'record-shift-cash-movement',
            'approve-shift-variance',
            'approve-shift-cash-movement',
            'reverse-party-payments',
            'view-delivery',
            'manage-delivery',
            'receive-delivery-cash',
        ])->unique()->values();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate([
                'name' => $perm,
                'guard_name' => 'web',
            ]);
        }

        $masterAdmin = Role::firstOrCreate([
            'name' => 'master admin',
            'guard_name' => 'web',
        ], [
            'branch_id' => null,
        ]);
        $masterAdmin->syncPermissions(Permission::all());

        $branchRoleService = app(BranchRoleService::class);

        foreach (Branch::query()->get() as $branch) {
            Register::firstOrCreate(
                ['branch_id' => $branch->id, 'code' => 'MAIN'],
                ['name' => 'Main Register', 'is_active' => true]
            );
            $admin = Role::firstOrCreate([
                'name' => $branchRoleService->internalNameForBranch('admin', $branch),
                'guard_name' => 'web',
            ], [
                'branch_id' => $branch->id,
            ]);
            // Branch Admin gets everything EXCEPT branch management and the
            // Master-Admin-only Chart-of-Accounts permission.
            $admin->syncPermissions(Permission::whereNotIn('name', [
                'view-branches',
                'manage-branches',
                'manage-accounts',
            ])->get());

            $manager = Role::firstOrCreate([
                'name' => $branchRoleService->internalNameForBranch('manager', $branch),
                'guard_name' => 'web',
            ], [
                'branch_id' => $branch->id,
            ]);
            $manager->syncPermissions(Permission::whereIn('name', [
                'refund-sale',
                'view-sales',
                'manage-products',
                'print-barcode-labels',
                'view-products',
                'view-stock',
                'adjust-stock',
                'view-reports',
                'view-customers',
                'view-vendors',
                'view-register-shifts',
                'open-register-shift',
                'close-own-register-shift',
                'manage-register-shifts',
                'record-shift-cash-movement',
                'approve-shift-variance',
                'approve-shift-cash-movement',
            ])->get());

            $delivery = Role::firstOrCreate([
                'name' => $branchRoleService->internalNameForBranch('delivery', $branch),
                'guard_name' => 'web',
            ], [
                'branch_id' => $branch->id,
            ]);
            $delivery->syncPermissions(Permission::whereIn('name', [
                'view-sales',
            ])->get());

            $salesman = Role::firstOrCreate([
                'name' => $branchRoleService->internalNameForBranch('salesman', $branch),
                'guard_name' => 'web',
            ], [
                'branch_id' => $branch->id,
            ]);
            $salesman->syncPermissions(Permission::whereIn('name', [
                'create-sales',
                'refund-sale',
                'view-sales',
                'view-customers',
                'view-vendors',
                'view-products',
                'view-register-shifts',
                'open-register-shift',
                'close-own-register-shift',
                'record-shift-cash-movement',
            ])->get());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
