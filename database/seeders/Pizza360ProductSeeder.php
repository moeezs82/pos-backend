<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use Illuminate\Support\Arr;

class Pizza360ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // How many products to create
        $products = [

            // PIZZAS
            // ['name' => 'Chicken Tikka Pizza S', 'price' => 400, 'cost_price' => 350],
            // ['name' => 'Chicken Tikka Pizza R', 'price' => 450, 'cost_price' => 400],
            // ['name' => 'Chicken Tikka Pizza M', 'price' => 850, 'cost_price' => 800],
            // ['name' => 'Chicken Tikka Pizza L', 'price' => 1300, 'cost_price' => 1200],
            // ['name' => 'Chicken Fajita Pizza S', 'price' => 400, 'cost_price' => 350],
            // ['name' => 'Chicken Fajita Pizza R', 'price' => 450, 'cost_price' => 400],
            // ['name' => 'Chicken Fajita Pizza M', 'price' => 850, 'cost_price' => 800],
            // ['name' => 'Chicken Fajita Pizza L', 'price' => 1300, 'cost_price' => 1200],
            // ['name' => 'Hot & Spicy Pizza S', 'price' => 400, 'cost_price' => 350],
            // ['name' => 'Hot & Spicy Pizza R', 'price' => 450, 'cost_price' => 400],
            // ['name' => 'Hot & Spicy Pizza M', 'price' => 850, 'cost_price' => 800],
            // ['name' => 'Hot & Spicy Pizza L', 'price' => 1300, 'cost_price' => 1200],
            // ['name' => 'Bar B.Q Pizza S', 'price' => 450, 'cost_price' => 400],
            // ['name' => 'Bar B.Q Pizza R', 'price' => 500, 'cost_price' => 450],
            // ['name' => 'Bar B.Q Pizza M', 'price' => 900, 'cost_price' => 800],
            // ['name' => 'Bar B.Q Pizza L', 'price' => 1350, 'cost_price' => 1200],
            // ['name' => 'Afghani Tikka Pizza S', 'price' => 450, 'cost_price' => 400],
            // ['name' => 'Afghani Tikka Pizza R', 'price' => 500, 'cost_price' => 450],
            // ['name' => 'Afghani Tikka Pizza M', 'price' => 900, 'cost_price' => 800],
            // ['name' => 'Afghani Tikka Pizza L', 'price' => 1350, 'cost_price' => 1200],
            // ['name' => 'Chicken Supreme Pizza S', 'price' => 450, 'cost_price' => 400],
            // ['name' => 'Chicken Supreme Pizza R', 'price' => 500, 'cost_price' => 450],
            // ['name' => 'Chicken Supreme Pizza M', 'price' => 900, 'cost_price' => 800],
            // ['name' => 'Chicken Supreme Pizza L', 'price' => 1350, 'cost_price' => 1200],
            // ['name' => 'Cheese Lover Pizza S', 'price' => 400, 'cost_price' => 350],
            // ['name' => 'Cheese Lover Pizza R', 'price' => 450, 'cost_price' => 400],
            // ['name' => 'Cheese Lover Pizza M', 'price' => 850, 'cost_price' => 800],
            // ['name' => 'Cheese Lover Pizza L', 'price' => 1300, 'cost_price' => 1200],
            // ['name' => 'Veg Lover Pizza S', 'price' => 400, 'cost_price' => 350],
            // ['name' => 'Veg Lover Pizza R', 'price' => 450, 'cost_price' => 400],
            // ['name' => 'Veg Lover Pizza M', 'price' => 850, 'cost_price' => 800],
            // ['name' => 'Veg Lover Pizza L', 'price' => 1300, 'cost_price' => 1200],
            // ['name' => 'Malai Boti Pizza S', 'price' => 450, 'cost_price' => 400],
            // ['name' => 'Malai Boti Pizza R', 'price' => 500, 'cost_price' => 450],
            // ['name' => 'Malai Boti Pizza M', 'price' => 900, 'cost_price' => 800],
            // ['name' => 'Malai Boti Pizza L', 'price' => 1350, 'cost_price' => 1200],
            // ['name' => 'Paneer Pizza S', 'price' => 450, 'cost_price' => 400],
            // ['name' => 'Paneer Pizza R', 'price' => 500, 'cost_price' => 450],
            // ['name' => 'Paneer Pizza M', 'price' => 900, 'cost_price' => 800],
            // ['name' => 'Paneer Pizza L', 'price' => 1350, 'cost_price' => 1200],
            // ['name' => 'Soya Bean Pizza S', 'price' => 450, 'cost_price' => 400],
            // ['name' => 'Soya Bean Pizza R', 'price' => 500, 'cost_price' => 450],
            // ['name' => 'Soya Bean Pizza M', 'price' => 900, 'cost_price' => 800],
            // ['name' => 'Soya Bean Pizza L', 'price' => 1350, 'cost_price' => 1200],
            // ['name' => 'Kabab Crust Pizza M', 'price' => 1250, 'cost_price' => 1100],
            // ['name' => 'Kabab Crust Pizza L', 'price' => 1750, 'cost_price' => 1600],
            // ['name' => 'Crown Crust Pizza M', 'price' => 1250, 'cost_price' => 1100],
            // ['name' => 'Crown Crust Pizza L', 'price' => 1600, 'cost_price' => 1400],
            // ['name' => 'Lava Pizza R', 'price' => 850, 'cost_price' => 750],
            // ['name' => 'Lava Pizza M', 'price' => 1300, 'cost_price' => 1200],
            // ['name' => '360 Special Pizza S', 'price' => 500, 'cost_price' => 400],
            // ['name' => '360 Special Pizza R', 'price' => 600, 'cost_price' => 500],
            // ['name' => '360 Special Pizza M', 'price' => 1000, 'cost_price' => 850],
            // ['name' => '360 Special Pizza L', 'price' => 1450, 'cost_price' => 1250],


            // // BURGERS
            // ['name' => 'Zinger Burger', 'price' => 350, 'cost_price' => 300],
            // ['name' => 'Special Burger', 'price' => 380, 'cost_price' => 320],
            // ['name' => 'Tower Burger', 'price' => 500, 'cost_price' => 420],
            // ['name' => 'Korean Zinger Burger', 'price' => 450, 'cost_price' => 400],
            // ['name' => 'Chicken Burger', 'price' => 250, 'cost_price' => 200],
            // ['name' => 'Dynamite Burger', 'price' => 350, 'cost_price' => 300],
            // ['name' => 'Paneer Burger', 'price' => 350, 'cost_price' => 300],


            // CHICKEN BROAST
            // ['name' => 'Chest Broast', 'price' => 500, 'cost_price' => 430],
            // ['name' => 'Leg Broast', 'price' => 450, 'cost_price' => 380],
            // ['name' => 'Hot Wings (6) ps', 'price' => 300, 'cost_price' => 250],
            // ['name' => 'Oven Wings (6) ps', 'price' => 350, 'cost_price' => 300],
            // ['name' => 'Korean Wings (6) ps', 'price' => 350, 'cost_price' => 300],

            // // FRIES
            // ['name' => 'Masala Fries', 'price' => 150, 'cost_price' => 110],
            // ['name' => 'Pizza Fries', 'price' => 350, 'cost_price' => 280],
            // ['name' => 'Loaded Fries', 'price' => 350, 'cost_price' => 280],

            // // NUGGETS
            // ['name' => 'Nuggets (6) ps', 'price' => 280, 'cost_price' => 230],
            // ['name' => 'Arabian Nuggets (5) ps', 'price' => 350, 'cost_price' => 300],

            // // PASTA
            // ['name' => 'White Sauces Pasta', 'price' => 350, 'cost_price' => 300],
            // ['name' => 'Red Sauces Pasta', 'price' => 350, 'cost_price' => 300],
            // ['name' => 'Crunchy Pasta', 'price' => 450, 'cost_price' => 380],
            // ['name' => 'Full Pasta', 'price' => 650, 'cost_price' => 550],

            // // SAUCE
            // ['name' => 'Coleslaw', 'price' => 30, 'cost_price' => 20],
            // ['name' => 'Special Sauce', 'price' => 50, 'cost_price' => 35],

            // // ROLLS
            // ['name' => 'Zinger Roll', 'price' => 280, 'cost_price' => 230],
            // ['name' => 'Chatni Chicken Roll', 'price' => 220, 'cost_price' => 180],
            // ['name' => 'Mayo Roll', 'price' => 220, 'cost_price' => 180],
            // ['name' => 'Kabab Mayo Roll', 'price' => 250, 'cost_price' => 210],
            // ['name' => 'Veg Roll', 'price' => 220, 'cost_price' => 180],
            // ['name' => 'Paneer Roll', 'price' => 300, 'cost_price' => 250],
            // ['name' => 'Soya Bean Roll', 'price' => 300, 'cost_price' => 250],
            // ['name' => 'Chicken Sandwich', 'price' => 300, 'cost_price' => 250],

            // // COLD DRINKS
            // ['name' => 'Cold Drink 300ml', 'price' => 70, 'cost_price' => 50],
            // ['name' => 'Cold Drink 500ml', 'price' => 100, 'cost_price' => 70],
            // ['name' => 'Cold Drink 1ltr', 'price' => 140, 'cost_price' => 100],
            // ['name' => 'Cold Drink 1.5ltr', 'price' => 180, 'cost_price' => 130],
            // ['name' => 'Cold Drink Jumbo', 'price' => 240, 'cost_price' => 180],


            // Deals
            [
                'name' => 'Royal Feast (1 Medium Pizza + 1 Large Pizza)',
                'price' => 2099,
                'cost_price' => null,
            ],

            [
                'name' => 'Burger Treat (2 Chicken Burger)',
                'price' => 499,
                'cost_price' => null,
            ],

            [
                'name' => 'Zinger Blast (3 Zinger Burger)',
                'price' => 949,
                'cost_price' => null,
            ],

            [
                'name' => 'Crispy Bites (5 Wings + 5 Nuggets)',
                'price' => 499,
                'cost_price' => null,
            ],

            [
                'name' => 'Pizza Combo (1 Small Pizza + 1 Medium Pizza)',
                'price' => 1199,
                'cost_price' => null,
            ],

            [
                'name' => 'Broast Feast (2 Chest Broast + 1 Leg Broast)',
                'price' => 1399,
                'cost_price' => null,
            ],

            [
                'name' => 'Roll Box (1 Mayo Roll + 1 Chatni Roll + 1 Zinger Roll)',
                'price' => 699,
                'cost_price' => null,
            ],

            [
                'name' => 'Snack Box (1 Leg Broast + 6 Arabian Nuggets)',
                'price' => 849,
                'cost_price' => null,
            ],

            [
                'name' => 'Pizza Party (4 Small Pizza)',
                'price' => 1549,
                'cost_price' => null,
            ],

            [
                'name' => 'Pasta Box (1 White Sauce Pasta + 6 Nuggets)',
                'price' => 649,
                'cost_price' => null,
            ],
        ];

        foreach ($products as $key => $p) {
            DB::transaction(function () use ($p, $key) {
                // Save product
                $data = array_merge($p, [
                    'sku' => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $p['name']), 0, 4)) . '-' . rand(1000, 9999),
                    'barcode' => null,
                    'description' => $p['name'] . " - Delicious pizza from Pizza360!",
                    'category_id' => null,
                    'vendor_id' => null,
                    'brand_id' => null,
                    // 'stock' => 0, // we'll set stock with opening logic
                    // 'tax_rate' => 10.00, // example tax rate
                    // 'tax_inclusive' => false,
                    // 'discount' => 0.00,
                    'is_active' => true
                ]);

                $product = Product::create(Arr::only($data, [
                    'sku',
                    'barcode',
                    'name',
                    'description',
                    'category_id',
                    'vendor_id',
                    'brand_id',
                    'price',
                    'cost_price',
                    // 'wholesale_price',
                    // 'stock',
                    // 'tax_rate',
                    // 'tax_inclusive',
                    'discount',
                    'is_active'
                ]));
            });
        }


        // Use factory to create skeleton products, then run same stock/accounting logic
        // \App\Models\Product::factory()->count(count($products))->make()->each(function ($p) {
        //     DB::transaction(function () use ($p) {
        //         // Save product
        //         $data = $p->toArray();
        //         // Ensure unique fields are null or unique if collision
        //         $data['sku'] = $data['sku'] ?: null;
        //         $data['barcode'] = $data['barcode'] ?: null;

        //         $product = Product::create(Arr::only($data, [
        //             'sku',
        //             'barcode',
        //             'name',
        //             'description',
        //             'category_id',
        //             'vendor_id',
        //             'brand_id',
        //             'price',
        //             'cost_price',
        //             'wholesale_price',
        //             // 'stock',
        //             // 'tax_rate',
        //             // 'tax_inclusive',
        //             'discount',
        //             'is_active'
        //         ]));

        //         // Simulate opening stock: random qty
        //         $qty = rand(0, 50);
        //         if ($qty > 0) {
        //             $unitCost = (float) ($product->cost_price ?? 0);
        //             $branchId = null; // change if you want to seed per-branch stocks
        //             $asOf = now()->toDateString();

        //             // 1) Update stocks with moving-average (opening)
        //             app(\App\Services\InventoryValuationWriteService::class)->receivePurchase(
        //                 productId: $product->id,
        //                 branchId: $branchId,
        //                 receiveQty: $qty,
        //                 unitPrice: $unitCost,
        //                 ref: 'OPENING'
        //             );

        //             // 2) Accumulate branch value for GL posting
        //             $value = round($qty * $unitCost, 2);

        //             // 3) Post GL JE per branch: DR Inventory (1400) / CR Opening Equity (3100)
        //             if ($value > 0) {
        //                 app(\App\Services\AccountingService::class)->post(
        //                     branchId: $branchId,
        //                     memo: "Opening stock for {$product->name} (#{$product->id})",
        //                     reference: $product,
        //                     lines: [
        //                         ['account_code' => '1400', 'debit' => $value, 'credit' => 0],
        //                         ['account_code' => '3100', 'debit' => 0,      'credit' => $value],
        //                     ],
        //                     entryDate: $asOf,
        //                     userId: 1 // if seeding from CLI, this may be null; set to a user id if needed
        //                 );
        //             }
        //         }

        //         // Reload relations if you want
        //         $product->load('category', 'brand', 'stocks');

        //         // Optionally output to console
        //         $this->command->info("Seeded product: {$product->id} - {$product->name} (opening qty: {$qty})");
        //     });
        // });
    }
}
