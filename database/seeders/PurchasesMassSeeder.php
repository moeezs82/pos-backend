<?php

namespace Database\Seeders;

use App\Models\Purchase;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchasesMassSeeder extends Seeder
{
    /** Total purchases to create */
    protected int $total = 10000;

    /** Purchases per DB transaction */
    protected int $batchSize = 250;

    /** Random date window (days back from now) */
    protected int $daysWindow = 180;

    /** Payment methods pool (free text per your schema) */
    protected array $payMethods = ['cash'];

    public function run(): void
    {
        ini_set('memory_limit', '1024M');

        // Pools
        $branchIds = DB::table('branches')->pluck('id')->all();
        $vendorIds = DB::table('vendors')->pluck('id')->all();
        $userIds   = DB::table('users')->pluck('id')->all(); // for received_by if you later add

        if (empty($branchIds) || empty($vendorIds)) {
            $this->command->warn('Need branches and vendors to seed purchases.');
            return;
        }

        // Pull products once (id + price/cost fallback)
        $products = DB::table('products')
            ->select('id',
                DB::raw('COALESCE(NULLIF(cost_price,0), NULLIF(price,0), 100) as base_price'))
            ->get()
            ->map(fn($p) => ['id' => (int)$p->id, 'price' => (float)$p->base_price])
            ->values()->all();

        if (empty($products)) {
            $this->command->warn('No products found — aborting PurchasesMassSeeder.');
            return;
        }

        $randOrNull = function(array $pool, float $probNull = 0.25) {
            if (empty($pool)) return null;
            return mt_rand() / mt_getrandmax() < $probNull
                ? null
                : $pool[array_rand($pool)];
        };

        $created = 0;
        while ($created < $this->total) {
            $toCreate = min($this->batchSize, $this->total - $created);

            DB::transaction(function () use (
                $toCreate, $branchIds, $vendorIds, $products, $randOrNull, &$created
            ) {
                for ($i = 0; $i < $toCreate; $i++) {
                    $branchId = $branchIds[array_rand($branchIds)];
                    $vendorId = $vendorIds[array_rand($vendorIds)];

                    // Choose 1–7 products for this purchase
                    $pickCount = random_int(1, min(7, count($products)));
                    $keys = array_rand($products, $pickCount);
                    $keys = is_array($keys) ? $keys : [$keys];
                    $picked = array_map(fn($k) => $products[$k], $keys);

                    // Timestamps
                    $createdAt = Carbon::now()
                        ->subDays(random_int(0, $this->daysWindow))
                        ->subMinutes(random_int(0, 1440));

                    // Random receive_now & expected_at/notes
                    $receiveNow = (mt_rand(0, 100) < 70); // 70% receive now
                    $expectedAt = $receiveNow
                        ? null
                        : $createdAt->copy()->addDays(random_int(2, 21)); // upcoming

                    $notes = (mt_rand(0, 100) < 20) ? 'Seeder auto-generated PO' : null;

                    // Build items (qty 1–20, purchase price around base ±20%)
                    $itemsPayload = [];
                    $subtotal = 0.0;

                    foreach ($picked as $p) {
                        $qty   = random_int(1, 20);
                        $price = round($p['price'] * (random_int(80, 120) / 100), 2);

                        $itemsPayload[] = [
                            'product_id'   => $p['id'],
                            'quantity'     => $qty,
                            'received_qty' => 0, // set below if receive_now
                            'price'        => $price,
                            'total'        => $qty * $price,
                            'created_at'   => $createdAt,
                            'updated_at'   => $createdAt,
                        ];
                        $subtotal += $qty * $price;
                    }

                    $discount = round($subtotal * (random_int(0, 10) / 100), 2);
                    $tax      = round($subtotal * (random_int(0, 8) / 100), 2);
                    $total    = max(0, $subtotal - $discount + $tax);

                    // Create Purchase
                    $purchase = Purchase::create([
                        'invoice_no'     => 'PUR-' . $createdAt->timestamp . '-' . Str::upper(Str::random(6)),
                        'vendor_id'      => $vendorId,
                        'branch_id'      => $branchId,
                        'subtotal'       => $subtotal,
                        'discount'       => $discount,
                        'tax'            => $tax,
                        'total'          => $total,
                        'status'         => 'pending', // will adjust after payments
                        'receive_status' => $receiveNow ? 'partial' : 'ordered',
                        'expected_at'    => $expectedAt,
                        'notes'          => $notes,
                        'created_at'     => $createdAt,
                        'updated_at'     => $createdAt,
                    ]);

                    // Items + optional receive_now
                    $itemsCreated = [];
                    foreach ($itemsPayload as $row) {
                        $item = $purchase->items()->create($row);
                        $itemsCreated[] = $item;

                        if ($receiveNow) {
                            // Clamp received_qty (0..quantity) — bias towards full receipt
                            $rcv = (mt_rand(0, 100) < 65)
                                ? $item->quantity
                                : random_int(0, $item->quantity);
                            if ($rcv > 0) {
                                // incrementStock like your controller does
                                $this->incrementStock($item->product_id, $branchId, $rcv);

                                $item->update(['received_qty' => $rcv]);

                                StockMovement::create([
                                    'product_id' => $item->product_id,
                                    'branch_id'  => $branchId,
                                    'type'       => 'purchase',
                                    'quantity'   => $rcv,
                                    'reference'  => $purchase->invoice_no,
                                    'created_at' => $createdAt,
                                    'updated_at' => $createdAt,
                                ]);
                            }
                        }
                    }

                    // Derive receive_status (ordered/partial/received)
                    if ($receiveNow) {
                        $totalQty     = array_sum(array_map(fn($it) => (int)$it->quantity, $itemsCreated));
                        $totalReceived= array_sum(array_map(fn($it) => (int)$it->received_qty, $itemsCreated));
                        $receiveStatus = ($totalReceived >= $totalQty) ? 'received' :
                                         (($totalReceived > 0) ? 'partial' : 'ordered');
                        $purchase->update(['receive_status' => $receiveStatus]);
                    }

                    // Payments (0–2), via relation so observers fire
                    $numPays   = random_int(0, 2);
                    $remaining = $total;
                    $paidTotal = 0.0;

                    for ($p = 0; $p < $numPays; $p++) {
                        if ($remaining <= 0) break;

                        $isLast = ($p === $numPays - 1);
                        $amount = $isLast
                            ? $remaining
                            : round($remaining * (random_int(30, 70) / 100), 2);
                        $amount = max(0.01, $amount);
                        $remaining = round($remaining - $amount, 2);
                        $paidTotal = round($paidTotal + $amount, 2);

                        // paid_at anywhere between createdAt and now
                        $paidAt = Carbon::createFromTimestamp(
                            random_int($createdAt->timestamp, Carbon::now()->timestamp)
                        );

                        $purchase->payments()->create([
                            'method'  => $this->payMethods[array_rand($this->payMethods)],
                            'amount'  => $amount,
                            'tx_ref'  => Str::upper(Str::random(10)),
                            'paid_at' => $paidAt,
                            'meta'    => null,
                        ]);
                    }

                    // Payment status update (pending/partial/paid)
                    $status = 'pending';
                    if ($paidTotal <= 0.0) {
                        $status = 'pending';
                    } elseif ($paidTotal + 0.009 >= $total) {
                        $status = 'paid';
                    } else {
                        $status = 'partial';
                    }
                    $purchase->update(['status' => $status]);

                    $created++;
                } // for purchases in batch
            }); // transaction

            $this->command->info("Seeded {$created}/{$this->total} purchases…");
        }

        $this->command->info("PurchasesMassSeeder complete: {$this->total} purchases generated.");
    }

    /**
     * Increment stock for (product,branch). Creates row if missing.
     */
    protected function incrementStock(int $productId, int $branchId, int $qty): void
    {
        // ensure row exists
        DB::table('product_stocks')->updateOrInsert(
            ['product_id' => $productId, 'branch_id' => $branchId],
            ['quantity' => DB::raw('COALESCE(quantity, 0)')]
        );

        DB::table('product_stocks')
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->increment('quantity', $qty);
    }
}
