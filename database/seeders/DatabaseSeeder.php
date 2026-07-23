<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Branch::firstOrCreate(
            ['name' => 'Main Branch'],
            ['location' => 'Main Location', 'is_active' => true]
        );

        $this->call([
            RolePermissionSeeder::class,
            AccountSeeder::class,
        ]);

        $master = User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Master Admin',
                'phone' => null,
                'password' => Hash::make('password'),
                'branch_id' => null,
                'is_active' => true,
            ]
        );

        if (method_exists($master, 'syncRoles')) {
            $master->syncRoles(['master admin']);
        }
    }
}
