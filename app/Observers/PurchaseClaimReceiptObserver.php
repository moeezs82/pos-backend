<?php

namespace App\Observers;

use App\Models\PurchaseClaimReceipt;

class PurchaseClaimReceiptObserver
{
    public function created(PurchaseClaimReceipt $rcv): void
    {
        app(\App\Services\CashSyncService::class)
            ->syncFromPurchaseClaimReceipt($rcv, optional($rcv->purchaseClaim)->branch_id);
    }
    public function updated(PurchaseClaimReceipt $rcv): void
    {
        if ($rcv->cash_transaction_id && $rcv->cashTransaction) {
            app(\App\Services\CashSyncService::class)->resync($rcv->cashTransaction, [
                'amount'   => $rcv->amount,
                'method'   => $rcv->method ?: 'cash',
                'txn_date' => optional($rcv->received_at)->toDateString() ?? now()->toDateString(),
                'reference'=> $rcv->reference,
            ]);
        }
    }
    public function deleted(PurchaseClaimReceipt $rcv): void
    {
        if ($rcv->cash_transaction_id && $rcv->cashTransaction) {
            app(\App\Services\CashSyncService::class)->remove($rcv->cashTransaction);
        }
    }
}
