<?php

namespace Database\Seeders;

use App\Http\Controllers\Api\V1\PurchaseController as V1PurchaseController;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Faker\Factory as Faker;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Product;
use App\Models\ProductStock;
use App\Services\VendorPaymentService;

class PurchasesMassSeeder extends Seeder
{
    public function run()
    {
        $this->command->info('Starting PurchaseMassSeeder...');

        // ---------- CONFIG ----------
        // Create opening stock for products missing product_stocks (fast bulk insert)
        $CREATE_OPENING_STOCK_FIRST = true;

        // If true: allow controller side-effects (posting, inventory receive, vendor payment)
        // For big runs, set false; for small accurate runs set true.
        $RUN_SIDE_EFFECTS = true;

        // Batch size: process this many purchases per outer loop iteration
        $BATCH_SIZE = 100;

        // Total purchases to generate
        $TOTAL_PURCHASES = 300;

        // ---------------------------------

        $faker = Faker::create();

        // Ensure minimal data exists
        if (Vendor::count() === 0) {
            Vendor::factory()->count(10)->create();
            $this->command->info('Created 10 vendors (factory).');
        }
        if (User::count() === 0) {
            User::factory()->count(3)->create();
            $this->command->info('Created 3 users (factory).');
        }
        if (Product::count() === 0) {
            Product::factory()->count(50)->create();
            $this->command->info('Created 50 products (factory).');
        }

        // Auth user for auth()->id() used in controller
        $systemUser = User::first() ?? User::factory()->create();
        Auth::loginUsingId($systemUser->id);

        $vendors = Vendor::all();
        $users = User::all();
        $products = Product::all();

        // Date window (last 12 months)
        $now = Carbon::now();
        $start = $now->copy()->subMonths(12);

        // --- Step 1: optional opening stock for products that lack product_stocks (branch_id = null) ---
        if ($CREATE_OPENING_STOCK_FIRST) {
            $this->command->info('Creating opening product_stocks (branch_id = null) for missing products...');

            $havingStocks = ProductStock::whereNull('branch_id')->select('product_id')->distinct()->pluck('product_id')->toArray();
            $missingProducts = $products->pluck('id')->diff($havingStocks)->values();

            if ($missingProducts->count() > 0) {
                $insertRows = [];
                $timestamp = now()->toDateTimeString();
                foreach ($missingProducts as $pid) {
                    $openQty = rand(5, 30);
                    $p = $products->firstWhere('id', $pid);
                    $unitCost = (float)($p->cost_price ?? 0.0);

                    $insertRows[] = [
                        'product_id' => $pid,
                        'branch_id'  => null,
                        'quantity'   => $openQty,
                        'avg_cost'   => $unitCost,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                DB::table('product_stocks')->insert($insertRows);
                $this->command->info('Inserted opening product_stocks for ' . count($insertRows) . ' products.');
            } else {
                $this->command->info('All products already have product_stocks (no opening stock created).');
            }
        }

        // --- Step 2: cache latest avg_cost per product (branch_id = null) ---
        $this->command->info('Caching avg_cost map for products (branch_id = null)...');

        $latest = DB::table('product_stocks')
            ->select('product_id', DB::raw('MAX(id) as max_id'))
            ->whereNull('branch_id')
            ->groupBy('product_id');

        $costRows = DB::table('product_stocks as ps')
            ->joinSub($latest, 'latest', function ($join) {
                $join->on('latest.max_id', '=', 'ps.id');
            })
            ->select('ps.product_id', 'ps.avg_cost')
            ->get();

        $costByProduct = [];
        foreach ($costRows as $r) {
            $costByProduct[(int)$r->product_id] = (float)$r->avg_cost;
        }
        // default to product cost_price if no stock row
        foreach ($products as $p) {
            if (!isset($costByProduct[$p->id])) {
                $costByProduct[$p->id] = (float)($p->cost_price ?? 0.0);
            }
        }
        $this->command->info('Cached avg_cost for ' . count($costByProduct) . ' products.');

        // Prepare controller + service instances (resolved from container)
        $controller = app()->make(V1PurchaseController::class);
        $vendorPaymentService = app()->make(VendorPaymentService::class);

        // --- Step 3: create purchases in batches, spread across 12 months ---
        $this->command->info("Creating {$TOTAL_PURCHASES} purchases in batches of {$BATCH_SIZE} (side-effects: " . ($RUN_SIDE_EFFECTS ? 'enabled' : 'disabled') . ")...");

        $purchasesCreated = 0;

        while ($purchasesCreated < $TOTAL_PURCHASES) {
            $toCreate = min($BATCH_SIZE, $TOTAL_PURCHASES - $purchasesCreated);

            for ($i = 0; $i < $toCreate; $i++) {
                // random invoice date within the 12-month window
                $invoiceDate = Carbon::createFromTimestamp(rand($start->timestamp, $now->timestamp));

                $vendor = $vendors->random();
                $createdBy = $users->random();

                // items per purchase
                $numItems = rand(1, 5);
                // pick distinct random products quickly
                $chosenProducts = $products->random(min(30, $products->count()))->shuffle()->take($numItems);

                $items = [];
                foreach ($chosenProducts as $p) {
                    // quantity
                    $qty = rand(1, 30);

                    // base price: try to use product->purchase_price or product->price
                    $basePrice = $p->purchase_price ?? $p->cost_price ?? $faker->randomFloat(2, 5, 500);

                    // small variance
                    $price = round(max(0.01, $basePrice * (1 + (rand(-10, 15) / 100))), 2);

                    // optional line discount percent (0..30)
                    $discountPct = (rand(0, 9) === 0) ? rand(1, 30) : 0; // ~10% chance

                    $items[] = [
                        'product_id'   => $p->id,
                        'quantity'     => $qty,
                        'price'        => $price,
                        'discount'     => $discountPct,
                        // not sending received_qty - controller will set received based on receive_now
                    ];
                }

                // purchase-level adjustments
                $purchaseDiscount = (rand(0, 6) === 0) ? $faker->randomFloat(2, 0, 100) : 0;
                $purchaseTax = (rand(0, 8) === 0) ? $faker->randomFloat(2, 0, 100) : 0;

                // sometimes include immediate vendor payment
                $includePayment = (rand(0, 3) === 0); // ~25% chance
                $payment = null;
                if ($includePayment) {
                    // approximate subtotal to pick sensible payment amount
                    $estimatedSubtotal = 0;
                    foreach ($items as $it) {
                        $line = $it['quantity'] * $it['price'];
                        $line -= $line * (max(0, min(100, $it['discount'])) / 100.0);
                        $estimatedSubtotal += $line;
                    }
                    $estimatedTotal = max(0, $estimatedSubtotal - $purchaseDiscount + $purchaseTax);
                    $amount = round($estimatedTotal * (rand(40, 100) / 100), 2); // partial to full
                    $methods = ['cash', 'bank', 'card', 'wallet'];

                    $payment = [
                        'method'    => $methods[array_rand($methods)],
                        'amount'    => max(0.01, $amount),
                        'paid_at'   => $invoiceDate->toDateString(),
                        'reference' => 'SEED-' . strtoupper(Str::random(6)),
                        'note'      => 'Seeded payment'
                    ];
                }

                // receive_now: if true the controller will call receivePurchase (inventory update)
                $receiveNow = (rand(0, 2) === 0); // ~33% immediate receive

                $data = [
                    'vendor_id'    => $vendor->id,
                    // omit branch_id entirely or set null. Controller validation allows nullable.
                    'branch_id'    => null,
                    'invoice_date' => $invoiceDate->toDateString(),
                    'items'        => $items,
                    'discount'     => $purchaseDiscount,
                    'tax'          => $purchaseTax,
                    'expected_at'  => $invoiceDate->copy()->addDays(rand(1, 14))->toDateString(),
                    'notes'        => $faker->sentence(6),
                    'receive_now'  => $receiveNow,
                ];

                if ($payment) {
                    $data['payment'] = $payment;
                }

                // Wrap each call in a transaction so partial failures don't corrupt the run
                DB::beginTransaction();
                try {
                    // Build a Request and call the controller store method.
                    $request = new Request($data);

                    // If you disabled RUN_SIDE_EFFECTS, avoid calling services in controller by
                    // temporarily using a fake service binding that is no-op. Here we still call
                    // controller but if you want zero side-effects set $RUN_SIDE_EFFECTS = false and:
                    if (!$RUN_SIDE_EFFECTS) {
                        // We bind a lightweight stub for VendorPaymentService so controller DI works.
                        app()->bind(VendorPaymentService::class, function () {
                            return new class {
                                public function create($d) { return null; }
                            };
                        });
                    }

                    // Call store() - this will execute your controller's logic including inventory posting if enabled.
                    $response = $controller->store($request, app(VendorPaymentService::class));

                    // Try to log invoice number from response (best-effort)
                    if (is_object($response) && method_exists($response, 'getData')) {
                        $payload = $response->getData(true);
                        $purchase = $payload['data']['purchase'] ?? null;
                        if ($purchase) {
                            $this->command->info("Created purchase: " . ($purchase['invoice_no'] ?? $purchase['id'] ?? 'unknown'));
                        } else {
                            $this->command->info("Created purchase (response OK) at {$invoiceDate->toDateString()}");
                        }
                    } else {
                        $this->command->info("Created purchase (non-standard response) at {$invoiceDate->toDateString()}");
                    }

                    DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    \Log::error('Purchase seeder error: ' . $e->getMessage());
                    $this->command->error('Failed to create seeded purchase: ' . $e->getMessage());
                    // continue with next purchase rather than aborting whole run
                }

                $purchasesCreated++;
            }

            $this->command->info("Created {$purchasesCreated}/{$TOTAL_PURCHASES} purchases so far...");
            // optional small pause: usleep(20000);
        }

        $this->command->info('PurchaseMassSeeder finished.');
    }
}
