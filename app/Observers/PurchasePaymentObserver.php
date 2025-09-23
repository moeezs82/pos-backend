<?php

namespace App\Observers;

use App\Models\PurchasePayment;
use App\Services\CashSyncService;

class PurchasePaymentObserver
{
    public function created(PurchasePayment $pp): void
    {
        app(CashSyncService::class)->syncFromPurchasePayment($pp, optional($pp->purchase)->branch_id);
    }

    public function updated(PurchasePayment $pp): void
    {
        if ($pp->cash_transaction_id && $pp->cashTransaction) {
            app(CashSyncService::class)->resync($pp->cashTransaction, [
                'amount'   => $pp->amount,
                'method'   => $pp->method ?: 'cash',
                'txn_date' => optional($pp->paid_at)->toDateString() ?? now()->toDateString(),
                'reference'=> $pp->tx_ref,
            ]);
        }
    }

    public function deleted(PurchasePayment $pp): void
    {
        if ($pp->cash_transaction_id && $pp->cashTransaction) {
            app(CashSyncService::class)->remove($pp->cashTransaction);
        }
    }
}
