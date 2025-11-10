<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\SaleItem;

class SalesMassSeeder extends Seeder
{
    public function run()
    {
        $this->command->info('Starting SalesMassSeeder...');

        // Ensure some base data exists
        if (Customer::count() === 0) {
            Customer::factory()->count(10)->create();
            $this->command->info('Created 10 customers (factory).');
        }
        if (User::count() === 0) {
            User::factory()->count(3)->create();
            $this->command->info('Created 3 users (factory).');
        }
        if (Product::count() === 0) {
            Product::factory()->count(20)->create();
            $this->command->info('Created 20 products (factory).');
        }

        // Ensure auth user exists (so services using auth()->id() don't get null)
        $systemUser = User::first() ?? User::factory()->create();
        Auth::loginUsingId($systemUser->id);
        $this->command->info("Logged in as system user id {$systemUser->id}");

        $customers = Customer::all();
        $users = User::all();
        $products = Product::all();

        $salesToCreate = 120; // change as needed
        $now = Carbon::now();
        $start = $now->copy()->subMonths(3);

        // CONFIG: percent chance to create opening stock if none exists
        $createOpeningStockIfMissingPct = 70; // 0-100. Set to 0 to always allow negative/oversell.

        for ($i = 0; $i < $salesToCreate; $i++) {
            $createdAt = Carbon::createFromTimestamp(rand($start->timestamp, $now->timestamp));

            $customer = $customers->random();
            $salesman = $users->random();
            $createdBy = $users->random();

            $numItems = rand(1, 5);

            // sample candidate products (increase sample if you want more variety)
            $candidateProducts = $products->shuffle()->take(30);
            $items = [];

            foreach ($candidateProducts as $p) {
                // NOTE: you're using branch_id = null in your seeder; keep same behavior
                $branchId = null;

                // find last ProductStock row for product + branch
                $ps = ProductStock::where('product_id', $p->id)
                    ->where('branch_id', $branchId)
                    ->orderBy('id', 'desc')
                    ->first();

                // If no stock row exists:
                if (!$ps) {
                    // decide whether to create opening stock (realistic) or allow negative/oversell
                    if (rand(1, 100) <= $createOpeningStockIfMissingPct) {
                        $openQty = rand(5, 30);
                        $unitCost = (float)($p->cost_price ?? 0.0);

                        try {
                            app(\App\Services\InventoryValuationWriteService::class)->receivePurchase(
                                productId: $p->id,
                                branchId: $branchId,
                                receiveQty: $openQty,
                                unitPrice: $unitCost,
                                ref: 'OPENING_SEED'
                            );
                        } catch (\Throwable $ex) {
                            // fallback insert to product_stocks to allow sale creation
                            ProductStock::create([
                                'product_id' => $p->id,
                                'branch_id'  => $branchId,
                                'quantity'   => $openQty,
                                'avg_cost'   => $unitCost,
                            ]);
                        }

                        // refresh $ps
                        $ps = ProductStock::where('product_id', $p->id)
                            ->where('branch_id', $branchId)
                            ->orderBy('id', 'desc')
                            ->first();
                    } else {
                        // Intentionally allow negative: create pseudo-ps with zero qty so we can oversell
                        $ps = null; // keep null, and proceed to allow oversell below
                    }
                }

                // Determine quantity to sell:
                // - If product stock exists and quantity > 0: limit sale qty to available (or small max)
                // - If product stock exists but qty <= 0 OR ps missing and we chose not to create opening stock:
                //     allow oversell/negative: pick random qty (1..10)
                if ($ps && ($ps->quantity ?? 0) > 0) {
                    $maxQty = max(1, min(10, intval($ps->quantity)));
                    $qty = rand(1, $maxQty);
                } else {
                    // allow negative/oversell
                    $qty = rand(1, 10);
                }

                // pricing and discount like before
                $unitPrice = round(max(0.01, $p->price * (1 + (rand(-10, 20) / 100))), 2);
                $discountPct = rand(0, 20);

                // push item — we create items regardless of ps existence/quantity
                $items[] = [
                    'product_id' => $p->id,
                    'quantity' => $qty,
                    'price' => $unitPrice,
                    'discount_pct' => $discountPct,
                ];

                if (count($items) >= $numItems) break;
            }

            // ensure we have items (should be true given oversell allowed)
            if (count($items) === 0) {
                $this->command->warn("No items chosen for sale iteration {$i} — skipping.");
                continue;
            }

            // compute totals (same logic as controller)
            $subtotal = collect($items)->sum(function ($it) {
                $qty = (float)($it['quantity'] ?? 0);
                $price = (float)($it['price'] ?? 0);
                $pct = (float)($it['discount_pct'] ?? 0);
                $pct = max(0, min(100, $pct));
                $line = $qty * $price;
                $line -= $line * ($pct / 100);
                return max(0, $line);
            });

            $discount = rand(50, 200);
            $tax = 0; // change if you want random tax
            $total = max(0, round($subtotal - $discount + $tax, 2));

            DB::transaction(function () use ($customer, $salesman, $createdBy, $items, $subtotal, $discount, $tax, $total, $createdAt) {
                $invoiceNo = 'INV-' . $createdAt->timestamp . '-' . Str::upper(Str::random(4));

                $sale = Sale::create([
                    'invoice_no'  => $invoiceNo,
                    'customer_id' => $customer->id,
                    'vendor_id'   => null,
                    'salesman_id' => $salesman->id,
                    'created_by'  => $createdBy->id,
                    'branch_id'   => null,
                    'subtotal'    => round($subtotal, 2),
                    'discount'    => round($discount, 2),
                    'tax'         => round($tax, 2),
                    'total'       => $total,
                    'status'      => 'pending',
                    'created_at'  => $createdAt,
                    'updated_at'  => $createdAt,
                ]);

                // build cost map like controller (fall back to 0.0)
                $productIds = collect($items)->pluck('product_id')->unique()->values()->toArray();
                $costByProduct = DB::table('product_stocks as ps')
                    ->join(
                        DB::raw('(SELECT product_id, MAX(id) AS max_id FROM product_stocks WHERE branch_id = ' . (int)$sale->branch_id . ' GROUP BY product_id) latest'),
                        'latest.max_id',
                        '=',
                        'ps.id'
                    )
                    ->where('ps.branch_id', $sale->branch_id)
                    ->whereIn('ps.product_id', $productIds)
                    ->pluck('ps.avg_cost', 'ps.product_id');

                $totalCogs = 0;

                foreach ($items as $it) {
                    $productId = (int)$it['product_id'];
                    $qty = (int)$it['quantity'];
                    $price = (float)$it['price'];
                    $discountPct = isset($it['discount_pct']) ? (float)$it['discount_pct'] : 0.0;
                    $discountPct = max(0.0, min(100.0, $discountPct));

                    $lineSubtotal = round($qty * $price, 2);
                    $lineDiscount = round($lineSubtotal * ($discountPct / 100.0), 2);
                    $lineTotal = round($lineSubtotal - $lineDiscount, 2);

                    $unitCost = (float)($costByProduct[$productId] ?? 0.0);
                    $lineCost = round($unitCost * $qty, 2);
                    $totalCogs += $lineCost;

                    $sale->items()->create([
                        'product_id' => $productId,
                        'quantity'   => $qty,
                        'price'      => $price,
                        'discount'   => $discountPct,
                        'total'      => $lineTotal,
                        'unit_cost'  => $unitCost,
                        'line_cost'  => $lineCost,
                    ]);
                }

                $sale->cogs = round($totalCogs, 2);
                $sale->gross_profit = round(($sale->total ?? 0) - $sale->cogs, 2);
                $sale->save();

                // Deduct stock and stamp costs (this may create negative product_stocks depending on your service rules)
                try {
                    app(\App\Services\SalePostingService::class)->deductStockAndStampCosts($sale->fresh('items'));
                } catch (\Throwable $ex) {
                    \Log::error('SalePostingService::deductStockAndStampCosts failed in seeder: ' . $ex->getMessage());
                    $this->command->error("Warning: deductStockAndStampCosts failed for sale {$sale->id} - continuing.");
                }

                try {
                    app(\App\Services\SalePostingService::class)->postSale($sale->fresh('items'));
                } catch (\Throwable $ex) {
                    \Log::error('SalePostingService::postSale failed in seeder: ' . $ex->getMessage());
                }

                // sometimes create a receipt
                if (rand(0, 100) < 50) {
                    $paymentPayload = [
                        'customer_id' => $sale->customer_id,
                        'branch_id'   => $sale->branch_id,
                        'sale_id' => $sale->id,
                        'received_at' => $sale->created_at->toDateString(),
                        'method'      => 'cash',
                        'amount'      => round(min($sale->total, max(1, $sale->total * (rand(50, 100) / 100))), 2),
                        'reference'   => "Payment for Sale #{$sale->invoice_no}",
                        'memo'   => "Seed payment for Sale #{$sale->invoice_no}",
                        'note'        => 'Seeded payment',
                    ];

                    try {
                        app(\App\Services\CustomerPaymentService::class)->create($paymentPayload);
                    } catch (\Throwable $ex) {
                        \Log::error('CustomerPaymentService::create failed in seeder: ' . $ex->getMessage());
                    }
                }

                $this->command->info("Seeded sale {$sale->id} (invoice: {$sale->invoice_no}) on {$sale->created_at->toDateString()}");
            });
        }

        $this->command->info('SalesMassSeeder finished.');
    }
}
