<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\CashSyncService;

class PaymentObserver
{
    public function created(Payment $payment): void
    {
        app(CashSyncService::class)->syncFromPayment($payment, optional($payment->sale)->branch_id);
    }

    public function updated(Payment $payment): void
    {
        if ($payment->cash_transaction_id && $payment->cashTransaction) {
            app(CashSyncService::class)->resync($payment->cashTransaction, [
                'amount'   => $payment->amount,
                'method'   => $payment->method,
                'txn_date' => $payment->received_on ?? now()->toDateString(),
                'reference'=> $payment->reference,
            ]);
        }
    }

    public function deleted(Payment $payment): void
    {
        if ($payment->cash_transaction_id && $payment->cashTransaction) {
            app(CashSyncService::class)->remove($payment->cashTransaction);
        }
    }
}
