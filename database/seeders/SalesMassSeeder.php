<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Customer;
use App\Models\User;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;

class SalesMassSeeder extends Seeder
{
    public function run()
    {
        $this->command->info('Starting SalesMassSeederFast...');

        // --- CONFIG (tune these for speed vs realism) ---
        // If true: create simple opening stock for products missing stock (done once up front)
        // Creating opening stock up-front cuts repeated calls to InventoryValuationWriteService.
        $CREATE_OPENING_STOCK_FIRST = true;

        // If true: call deductStockAndStampCosts & postSale for each sale (slower).
        // Recommended: set to false for the big run, then run smaller batches with it true.
        $RUN_POSTING = true;

        // batch size: process this many sales per loop iteration (affects memory & DB load)
        $BATCH_SIZE = 200;

        // total sales to generate
        $TOTAL_SALES = 1200;
        // -------------------------------------------------

        // Ensure minimal data exists
        if (Customer::count() === 0) {
            Customer::factory()->count(10)->create();
            $this->command->info('Created 10 customers (factory).');
        }
        if (User::count() === 0) {
            User::factory()->count(3)->create();
            $this->command->info('Created 3 users (factory).');
        }
        if (Product::count() === 0) {
            Product::factory()->count(50)->create();
            $this->command->info('Created 50 products (factory).');
        }

        // Auth system user so services that use auth()->id() see a value
        $systemUser = User::first() ?? User::factory()->create();
        Auth::loginUsingId($systemUser->id);

        $customers = Customer::all();
        $users = User::all();
        $products = Product::all();

        // Date window (last 12 months)
        $now = Carbon::now();
        $start = $now->copy()->subMonths(12);

        // --- Step 1: create opening stock for products that have none (once) ---
        if ($CREATE_OPENING_STOCK_FIRST) {
            $this->command->info('Creating opening stock for products missing product_stocks (single pass)...');

            // Fetch product ids that have any product_stock row for branch_id = null
            $havingStocks = ProductStock::whereNull('branch_id')->select('product_id')->distinct()->pluck('product_id')->toArray();

            $missingProducts = $products->pluck('id')->diff($havingStocks)->values();

            if ($missingProducts->count() > 0) {
                $insertRows = [];
                $timestamp = now()->toDateTimeString();
                foreach ($missingProducts as $pid) {
                    // small realistic opening stock
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

                // Bulk insert to product_stocks (fast). We skip calling InventoryValuationWriteService here — if you need
                // ledger entries for these opening stocks, run a smaller seeder with RUN_POSTING enabled.
                DB::table('product_stocks')->insert($insertRows);
                $this->command->info('Inserted opening product_stocks for ' . count($insertRows) . ' products.');
            } else {
                $this->command->info('All products already have product_stocks (no opening stock created).');
            }
        }

        // --- Step 2: build a cached avg_cost map (one query) for branch_id = null ---
        // Map: product_id => avg_cost (latest product_stocks row)
        $this->command->info('Caching avg_cost map for products (single query)...');

        // Build a subquery to get latest id per product for branch_id = null
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

        // If some products aren't present in product_stocks, default to 0.0
        foreach ($products as $p) {
            if (!isset($costByProduct[$p->id])) {
                $costByProduct[$p->id] = (float)($p->cost_price ?? 0.0);
            }
        }

        $this->command->info('Cached avg_cost for ' . count($costByProduct) . ' products.');

        // --- Step 3: create sales in batches, minimize per-iteration DB hits ---
        $this->command->info("Creating {$TOTAL_SALES} sales in batches of {$BATCH_SIZE} (posting enabled: " . ($RUN_POSTING ? 'yes' : 'no') . ")...");

        $salesCreated = 0;
        $batchStart = 0;

        while ($salesCreated < $TOTAL_SALES) {
            $toCreate = min($BATCH_SIZE, $TOTAL_SALES - $salesCreated);

            for ($j = 0; $j < $toCreate; $j++) {
                // sale date random in window
                $createdAt = Carbon::createFromTimestamp(rand($start->timestamp, $now->timestamp));

                $customer = $customers->random();
                $salesman = $users->random();
                $createdBy = $users->random();

                $numItems = rand(1, 4);

                // pick random products quickly (no DB queries)
                $chosenProducts = $products->random(min(20, $products->count()))->shuffle()->take($numItems);

                $items = [];
                foreach ($chosenProducts as $p) {
                    // decide qty; allow oversell
                    $qty = rand(1, 6);

                    // compute unit price with small variance
                    $unitPrice = round(max(0.01, $p->price * (1 + (rand(-8, 15) / 100))), 2);

                    $discountPct = rand(0, 15);

                    $items[] = [
                        'product_id' => $p->id,
                        'quantity'   => $qty,
                        'price'      => $unitPrice,
                        'discount_pct' => $discountPct
                    ];
                }

                // compute totals (controller logic)
                $subtotal = 0;
                foreach ($items as $it) {
                    $line = $it['quantity'] * $it['price'];
                    $line -= $line * (max(0, min(100, $it['discount_pct'])) / 100);
                    $subtotal += $line;
                }
                $discount = rand(20, 150); // small random per-sale discount
                $tax = 0;
                $total = max(0, round($subtotal - $discount + $tax, 2));

                // create sale (Eloquent per Sale is relatively cheap)
                $sale = Sale::create([
                    'invoice_no'  => 'INV-' . $createdAt->timestamp . '-' . Str::upper(Str::random(4)),
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

                // Prepare sale_items rows for bulk insert, but first ensure unit price respects cost+margin
                $nowStr = now()->toDateTimeString();
                $rowsToInsert = [];
                foreach ($items as $it) {
                    $prodId = (int)$it['product_id'];
                    $qty = (int)$it['quantity'];

                    // get cached unit cost (0.0 default)
                    $unitCost = (float)($costByProduct[$prodId] ?? 0.0);

                    // ENFORCE minimum margin: price must be at least unitCost * (1 + MIN_MARGIN)
                    $MIN_MARGIN_PCT = 0.10; // 10% minimum gross margin
                    // baseline price derived from product list price, but ensure >= cost*(1+MIN_MARGIN_PCT)
                    $pModel = $products->firstWhere('id', $prodId);
                    $listPrice = $pModel ? (float)$pModel->price : max(0.01, ($unitCost * (1 + $MIN_MARGIN_PCT)));
                    // allow a small random variance but respect minimum margin
                    $proposedPrice = round(max(0.01, $listPrice * (1 + (rand(-8, 12) / 100))), 2);
                    $unitPrice = max($proposedPrice, round($unitCost * (1 + $MIN_MARGIN_PCT), 2));

                    // clamp quantity
                    $price = $unitPrice;
                    $discountPct = (float)($it['discount_pct'] ?? 0.0);
                    $discountPct = max(0.0, min(100.0, $discountPct));

                    $lineSubtotal = round($qty * $price, 2);
                    $lineDiscount = round($lineSubtotal * ($discountPct / 100.0), 2);
                    $lineTotal = round($lineSubtotal - $lineDiscount, 2);

                    $lineCost = round($unitCost * $qty, 2);

                    $rowsToInsert[] = [
                        'sale_id' => $sale->id,
                        'product_id' => $prodId,
                        'quantity' => $qty,
                        'price' => $price,
                        'discount' => $discountPct,
                        'total' => $lineTotal,
                        'unit_cost' => $unitCost,
                        'line_cost' => $lineCost,
                        'created_at' => $nowStr,
                        'updated_at' => $nowStr,
                    ];
                }

                // Bulk insert items
                DB::table('sale_items')->insert($rowsToInsert);

                // Recompute correct subtotal from inserted items (avoid previously computed random subtotal)
                $itemsTotal = array_sum(array_column($rowsToInsert, 'total'));

                // Bound the per-sale discount so it can't eat all margin
                $MAX_SALE_DISCOUNT_PCT = 0.30; // max 30% of items total as sale-level discount
                $randomSaleDiscount = $discount; // your previous random discount number
                $discountCap = round($itemsTotal * $MAX_SALE_DISCOUNT_PCT, 2);
                $appliedDiscount = min($randomSaleDiscount, $discountCap, $itemsTotal * 0.9); // never exceed 90% of items total

                // recompute totals & costs properly
                $sale->subtotal = round($itemsTotal, 2);
                $sale->discount = round($appliedDiscount, 2);
                $sale->tax = round($tax, 2);
                $sale->total = max(0, round($sale->subtotal - $sale->discount + $sale->tax, 2));

                // cogs from inserted rows:
                $cogs = array_sum(array_column($rowsToInsert, 'line_cost'));
                $sale->cogs = round($cogs, 2);

                // gross profit computed from revenue minus cost
                $sale->gross_profit = round($sale->total - $sale->cogs, 2);
                $sale->save();

                // Optionally run posting for accuracy (slow). Run only when you want to update real ledger/stock.
                if ($RUN_POSTING) {
                    try {
                        app(\App\Services\SalePostingService::class)->deductStockAndStampCosts($sale->fresh('items'));
                        app(\App\Services\SalePostingService::class)->postSale($sale->fresh('items'));
                    } catch (\Throwable $ex) {
                        \Log::error('Posting error for sale ' . $sale->id . ': ' . $ex->getMessage());
                    }
                }

                // Occasionally create a payment but keep it light
                if (rand(0, 100) < 25) {
                    $paymentPayload = [
                        'customer_id' => $sale->customer_id,
                        'branch_id' => $sale->branch_id,
                        'sale_id' => $sale->id,
                        'received_at' => $sale->created_at->toDateString(),
                        'method' => 'cash',
                        'amount' => round(min($sale->total, max(1, $sale->total * (rand(60, 100) / 100))), 2),
                        'reference' => "Payment for Sale #{$sale->invoice_no}",
                        'memo' => "Seed payment for Sale #{$sale->invoice_no}",
                        'note' => 'Seeded payment',
                    ];

                    // light try-catch so occasional failure doesn't abort
                    try {
                        app(\App\Services\CustomerPaymentService::class)->create($paymentPayload);
                    } catch (\Throwable $ex) {
                        \Log::error('Payment create failed in seeder: ' . $ex->getMessage());
                    }
                }

                $salesCreated++;
            }

            $this->command->info("Created {$salesCreated}/{$TOTAL_SALES} sales so far...");
            // small sleep can be added if you want DB to breathe: usleep(20000);
        }

        $this->command->info('SalesMassSeederFast finished.');
    }
}
