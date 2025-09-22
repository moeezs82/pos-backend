<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PurchasePayment;
use App\Services\CashSyncService;
use Illuminate\Console\Command;

class BackfillCashTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cashbook:backfill {--branch=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create cash_transactions for existing payments & purchase_payments';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $branchId = $this->option('branch') ? (int)$this->option('branch') : null;
        $svc = app(CashSyncService::class);

        $this->info('Backfilling Sales payments...');
        Payment::whereNull('cash_transaction_id')->chunkById(500, function ($chunk) use ($svc, $branchId) {
            foreach ($chunk as $p) {
                $svc->syncFromPayment($p, $branchId ?? optional($p->sale)->branch_id);
            }
        });

        $this->info('Backfilling Purchase payments...');
        PurchasePayment::whereNull('cash_transaction_id')->chunkById(500, function ($chunk) use ($svc, $branchId) {
            foreach ($chunk as $pp) {
                $svc->syncFromPurchasePayment($pp, $branchId ?? optional($pp->purchase)->branch_id);
            }
        });

        $this->info('Backfill complete.');
        return self::SUCCESS;
    }
}
