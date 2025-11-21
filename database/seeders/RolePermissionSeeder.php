<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define permissions
        $permissions = [
            'manage-stock',
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
            'view-categories',
            'manage-categories',
            'view-brands',
            'manage-brands',
            'adjust-stock',
            'create-sales',
            'manage-sales',
            'refund-sale',
            'view-sales',
            'manage-purchases',
            'view-purchases',
            'manage-users',
            'view-branches',
            'manage-branches',
            'view-cashbook',
            'manage-cashbook',
            'manage-accounts',
            'view-roles',
            'manage-roles',
            'manage-receipts',
            'manage-payments',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        // Define roles
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $manager = Role::firstOrCreate(['name' => 'manager']);
        // $cashier = Role::firstOrCreate(['name' => 'cashier']);
        $salesman = Role::firstOrCreate(['name' => 'salesman']);

        // Assign permissions
        $admin->givePermissionTo(Permission::all());

        $manager->givePermissionTo([
            'refund-sale',
            'view-sales',
            'manage-products',
            'manage-stock',
            'view-reports'
        ]);

        // $cashier->givePermissionTo([
        //     'create-sales',
        //     'refund-sale',
        //     'view-sales'
        // ]);
        $salesman->givePermissionTo([
            'create-sales',
            'refund-sale',
            'view-sales',
            'view-customers',
            'view-vendors',
            'view-products'
        ]);
    }
}
