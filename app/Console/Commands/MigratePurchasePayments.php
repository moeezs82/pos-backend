<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\PurchasePayment;
use App\Models\VendorPayment;
use App\Services\AccountingService;
use App\Services\CashSyncService;

class MigratePurchasePayments extends Command
{
    protected $signature = 'migrate:purchase-payments {--batch=100}';
    protected $description = 'Migrate purchase_payments into vendor_payments and post ledger JE';

    public function handle(AccountingService $acc, CashSyncService $cash)
    {
        $batch = (int)$this->option('batch');

        PurchasePayment::query()
            ->orderBy('id')
            ->chunk($batch, function($rows) use ($acc, $cash) {
                foreach ($rows as $pp) {
                    DB::transaction(function () use ($pp, $acc, $cash) {
                        // Skip already migrated (heuristic: existing vendor_payment with same source)
                        $exists = DB::table('vendor_payments')
                            ->where('reference', $pp->tx_ref)
                            ->where('amount', $pp->amount)
                            ->where('vendor_id', optional($pp->purchase)->vendor_id)
                            ->exists();
                        if ($exists) return;

                        $vendorId = optional($pp->purchase)->vendor_id;
                        if (!$vendorId) {
                            // fallback: skip or log
                            $this->warn("Skipping PurchasePayment {$pp->id} - no vendor on purchase.");
                            return;
                        }

                        // Create vendor_payment
                        $vp = VendorPayment::create([
                            'vendor_id'  => $vendorId,
                            'branch_id'  => $pp->branch_id ?? optional($pp->purchase)->branch_id,
                            'paid_at'    => $pp->paid_at ?? $pp->created_at,
                            'method'     => $pp->method ?? 'cash',
                            'amount'     => round($pp->amount, 2),
                            'reference'  => $pp->tx_ref,
                            'note'       => 'Migrated from purchase_payment #' . $pp->id,
                            'created_by' => $pp->created_by ?? null,
                        ]);

                        // Post GL: DR AP (2000) / CR Cash/Bank (mapped by method)
                        $cashAccount = $cash->mapMethodToAccount($vp->method, $vp->branch_id);
                        $acc->post(
                            $vp->branch_id,
                            "Migrated purchase_payment #{$pp->id}",
                            $vp,
                            [
                                ['account_code' => '2000',              'debit' => $vp->amount, 'credit' => 0],
                                ['account_code' => $cashAccount->code,  'debit' => 0,           'credit' => $vp->amount],
                            ],
                            $vp->paid_at ? $vp->paid_at->toDateString() : now()->toDateString(),
                            $vp->created_by
                        );

                        // Optional: create allocation row to keep traceability
                        DB::table('vendor_payment_allocations')->insert([
                            'vendor_payment_id' => $vp->id,
                            'purchase_id'       => $pp->purchase_id,
                            'amount'            => $pp->amount,
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ]);

                        // Mark the original purchase_payment as migrated (optional)
                        $pp->update(['migrated_to_vendor_payment_id' => $vp->id]);
                    });
                }
            });

        $this->info('Migration complete.');
    }
}
