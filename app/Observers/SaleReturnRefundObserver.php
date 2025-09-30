<?php

namespace App\Observers;

use App\Models\SaleReturnRefund;

class SaleReturnRefundObserver
{
    public function created(SaleReturnRefund $r): void
    {
        app(\App\Services\CashSyncService::class)
            ->syncFromSaleReturnRefund($r, optional($r->saleReturn)->branch_id);
    }
    public function updated(SaleReturnRefund $r): void
    {
        if ($r->cash_transaction_id && $r->cashTransaction) {
            app(\App\Services\CashSyncService::class)->resync($r->cashTransaction, [
                'amount'   => $r->amount,
                'method'   => $r->method ?: 'cash',
                'txn_date' => optional($r->refunded_at)->toDateString() ?? now()->toDateString(),
                'reference'=> $r->reference,
            ]);
        }
    }
    public function deleted(SaleReturnRefund $r): void
    {
        if ($r->cash_transaction_id && $r->cashTransaction) {
            app(\App\Services\CashSyncService::class)->remove($r->cashTransaction);
        }
    }
}
