<?php

namespace Database\Seeders;

use App\Models\Sale;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesMassSeeder extends Seeder
{
    protected int $total = 10000;   // how many sales
    protected int $batchSize = 250;  // per transaction
    protected array $paymentMethods = ['cash'];

    public function run(): void
    {
        ini_set('memory_limit', '1024M');

        // Core pools
        $branchIds   = DB::table('branches')->pluck('id')->all();
        $customerIds = DB::table('customers')->pluck('id')->all();
        $vendorIds   = DB::table('vendors')->pluck('id')->all();
        $userIds     = DB::table('users')->pluck('id')->all();

        if (empty($branchIds)) {
            $this->command->warn('No branches found — aborting SalesMassSeeder.');
            return;
        }

        // Pull all products once (id + price). We don’t care about stock; we allow negatives.
        $products = DB::table('products')->select('id', 'price')->get()->map(fn($p) => [
            'id' => (int)$p->id,
            'price' => (float)$p->price,
        ])->values()->all();

        if (empty($products)) {
            $this->command->warn('No products found — aborting SalesMassSeeder.');
            return;
        }

        $randOrNull = function(array $pool, float $probNull = 0.25) {
            if (empty($pool)) return null;
            return mt_rand() / mt_getrandmax() < $probNull
                ? null
                : $pool[array_rand($pool)];
        };

        $salesCreated = 0;

        while ($salesCreated < $this->total) {
            $toCreate = min($this->batchSize, $this->total - $salesCreated);

            DB::transaction(function () use (
                $toCreate, $branchIds, $customerIds, $vendorIds, $userIds,
                $products, $randOrNull, &$salesCreated
            ) {
                for ($i = 0; $i < $toCreate; $i++) {

                    // Random branch & parties
                    $branchId   = $branchIds[array_rand($branchIds)];
                    $customerId = $randOrNull($customerIds, 0.40);
                    $vendorId   = $randOrNull($vendorIds,   0.65);
                    $salesmanId = $randOrNull($userIds,     0.20);
                    $createdBy  = $randOrNull($userIds,     0.00) ?? ($userIds[0] ?? 1);

                    // Choose 1–5 random products (we allow repeats across sales)
                    $pickCount = random_int(1, min(5, count($products)));
                    $keys = array_rand($products, $pickCount);
                    $keys = is_array($keys) ? $keys : [$keys];
                    $picked = array_map(fn($k) => $products[$k], $keys);

                    // Build item payloads (qty 1–5) using product price
                    $createdAt = Carbon::now()
                        ->subDays(random_int(0, 120))
                        ->subMinutes(random_int(0, 1440));

                    $items = [];
                    $subtotal = 0.0;

                    foreach ($picked as $p) {
                        $qty   = random_int(1, 5);
                        $price = (float) $p['price'];

                        $items[] = [
                            'product_id' => $p['id'],
                            'quantity'   => $qty,
                            'price'      => $price,
                            'total'      => $qty * $price,
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ];
                        $subtotal += $qty * $price;
                    }

                    $discount = round($subtotal * (random_int(0, 15) / 100), 2);
                    $tax      = round($subtotal * (random_int(0, 10) / 100), 2);
                    $total    = max(0, $subtotal - $discount + $tax);

                    // Create sale
                    $sale = Sale::create([
                        'invoice_no' => 'INV-' . $createdAt->timestamp . '-' . Str::upper(Str::random(6)),
                        'customer_id'=> $customerId,
                        'vendor_id'  => $vendorId,
                        'salesman_id'=> $salesmanId,
                        'created_by' => $createdBy,
                        'branch_id'  => $branchId,
                        'subtotal'   => $subtotal,
                        'discount'   => $discount,
                        'tax'        => $tax,
                        'total'      => $total,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);

                    // Items
                    $sale->items()->createMany($items);

                    // Stock movements + decrement (allow negative)
                    foreach ($items as $it) {
                        // ensure row exists; start at 0 if not found
                        DB::table('product_stocks')->updateOrInsert(
                            ['product_id' => $it['product_id'], 'branch_id' => $branchId],
                            ['quantity' => DB::raw('COALESCE(quantity, 0)')]
                        );
                        // decrement (can go negative)
                        DB::table('product_stocks')
                            ->where('product_id', $it['product_id'])
                            ->where('branch_id', $branchId)
                            ->decrement('quantity', (int) $it['quantity']);

                        StockMovement::create([
                            'product_id' => $it['product_id'],
                            'branch_id'  => $branchId,
                            'type'       => 'sale',
                            'quantity'   => -$it['quantity'],
                            'reference'  => $sale->invoice_no,
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ]);
                    }

                    // Payments via Eloquent (observers fire)
                    $numPayments = random_int(0, 2);
                    $remaining   = $total;

                    for ($p = 0; $p < $numPayments; $p++) {
                        if ($remaining <= 0) break;

                        $isLast = ($p === $numPayments - 1);
                        $amount = $isLast
                            ? $remaining
                            : round($remaining * (random_int(20, 70) / 100), 2);
                        $amount = max(0.01, $amount);
                        $remaining = round($remaining - $amount, 2);

                        // received_on between sale date and today
                        $receivedOn = Carbon::createFromTimestamp(
                            random_int($createdAt->timestamp, Carbon::now()->timestamp)
                        )->toDateString();

                        $sale->payments()->create([
                            'amount'      => $amount,
                            'method'      => $this->paymentMethods[array_rand($this->paymentMethods)],
                            'reference'   => Str::upper(Str::random(8)),
                            'received_by' => $randOrNull($userIds, 0.10),
                            'received_on' => $receivedOn,
                        ]);
                    }

                    $salesCreated++;
                } // for batch
            });

            $this->command->info("Seeded {$salesCreated}/{$this->total} sales…");
        }

        $this->command->info("SalesMassSeeder complete: {$this->total} sales generated.");
    }
}
