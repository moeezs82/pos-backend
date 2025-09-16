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
            'create-sale',
            'refund-sale',
            'view-sales',
            'manage-products',
            'manage-stock',
            'view-reports',
            'manage-users',
            'view-stock',
            'adjust-stock',
            'manage-branches',
            'view-products',
            'manage-products',
            'view-categories',
            'manage-categories',
            'view-brands',
            'manage-brands',
            'view-stock',
            'adjust-stock',
            'create-sale',
            'refund-sale',
            'view-sales',
            'view-reports',
            'manage-users',
            'view-branches',
            'manage-branches',
            'view-stock',
            'adjust-stock'
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        // Define roles
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $manager = Role::firstOrCreate(['name' => 'manager']);
        $cashier = Role::firstOrCreate(['name' => 'cashier']);

        // Assign permissions
        $admin->givePermissionTo(Permission::all());

        $manager->givePermissionTo([
            'create-sale',
            'refund-sale',
            'view-sales',
            'manage-products',
            'manage-stock',
            'view-reports'
        ]);

        $cashier->givePermissionTo([
            'create-sale',
            'refund-sale',
            'view-sales'
        ]);
    }
}
