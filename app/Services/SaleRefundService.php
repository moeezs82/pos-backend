<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleRefund;
use Illuminate\Validation\ValidationException;

class SaleRefundService
{
    public function __construct(
        protected CashSyncService $cashSync,
        protected AccountingService $accounting,
    ) {}

    public function createForNegativeSale(Sale $sale, array $data): SaleRefund
    {
        if ((float) $sale->total >= 0) {
            throw ValidationException::withMessages([
                'refund' => ['An inline refund is only valid for a negative-total sale.'],
            ]);
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);
        $refundable = round(abs((float) $sale->total), 2);
        if ($amount !== $refundable) {
            throw ValidationException::withMessages([
                'refund.amount' => ["Inline refund must equal the negative sale total ({$refundable})."],
            ]);
        }

        $method = $data['method'] ?? 'cash';
        if (!in_array($method, ['cash', 'bank', 'card', 'wallet'], true)) {
            throw ValidationException::withMessages([
                'refund.method' => ['Refund method must be cash, bank, card, or wallet.'],
            ]);
        }
        $account = $this->cashSync->mapMethodToAccount($method, (int) $sale->branch_id);
        $refund = SaleRefund::create([
            'sale_id' => $sale->id,
            'register_shift_id' => $sale->register_shift_id,
            'amount' => $amount,
            'method' => $method,
            'reference' => $data['reference'] ?? "Refund for Sale #{$sale->invoice_no}",
            'refunded_at' => $data['refunded_at'] ?? $sale->created_at ?? now(),
            'created_by' => $sale->created_by,
        ]);

        $transaction = CashTransaction::create([
            'txn_date' => $refund->refunded_at->toDateString(),
            'account_id' => $account->id,
            'branch_id' => $sale->branch_id,
            'register_shift_id' => $sale->register_shift_id,
            'type' => 'payment',
            'amount' => $amount,
            'method' => $method,
            'reference' => $refund->reference,
            'note' => 'Inline sale return refund',
            'status' => 'approved',
            'created_by' => $sale->created_by,
            'source_type' => SaleRefund::class,
            'source_id' => $refund->id,
            'counterparty_type' => $sale->customer_id ? Customer::class : null,
            'counterparty_id' => $sale->customer_id,
        ]);
        $refund->update(['cash_transaction_id' => $transaction->id]);

        $this->accounting->post(
            branchId: $sale->branch_id,
            memo: "Refund paid for Sale #{$sale->invoice_no}",
            reference: $refund,
            lines: [
                [
                    'account_code' => config('accounts.receiveable_account', '1200'),
                    'debit' => $amount,
                    'credit' => 0,
                    'party_type' => $sale->customer_id ? Customer::class : null,
                    'party_id' => $sale->customer_id,
                ],
                ['account_code' => $account->code, 'debit' => 0, 'credit' => $amount],
            ],
            entryDate: $refund->refunded_at->toDateString(),
            userId: $sale->created_by,
        );

        return $refund->fresh('cashTransaction');
    }
}
