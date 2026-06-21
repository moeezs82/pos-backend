<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CustomerVendorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Branch::query()
            ->select('id')
            ->each(function (Branch $branch): void {
                Customer::factory()
                    ->count(1000)
                    ->create([
                        'branch_id' => $branch->id,
                    ]);

                Vendor::factory()
                    ->count(1000)
                    ->create([
                        'branch_id' => $branch->id,
                    ]);
                Product::factory()
                    ->count(1000)
                    ->create([
                        'branch_id' => $branch->id
                    ]);
            });
    }
}
