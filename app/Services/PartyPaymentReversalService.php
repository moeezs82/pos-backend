<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Receipt;
use App\Models\Vendor;
use App\Models\VendorPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PartyPaymentReversalService
{
    private AccountingService $accounting;
    private CashSyncService $cashSync;
    private PaymentMethodService $paymentMethods;

    public function __construct(
        AccountingService $accounting,
        CashSyncService $cashSync,
        PaymentMethodService $paymentMethods
    ) {
        $this->accounting = $accounting;
        $this->cashSync = $cashSync;
        $this->paymentMethods = $paymentMethods;
    }

    public function reverseCustomerReceipt(Receipt $receipt, string $reason, int $userId): Receipt
    {
        return DB::transaction(function () use ($receipt, $reason, $userId) {
            $receipt = Receipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $this->assertNotReversed($receipt);
            $this->assertNoStoredAllocations(
                'receipt_allocations',
                'receipt_id',
                (int) $receipt->id,
                'receipt'
            );

            $cashAccount = $this->cashSync->mapMethodToAccount(
                (string) $receipt->method,
                $receipt->branch_id ? (int) $receipt->branch_id : null,
                true
            );

            $journal = $this->accounting->post(
                branchId: $receipt->branch_id,
                memo: "REVERSAL: Customer receipt #{$receipt->id} — {$reason}",
                reference: $receipt,
                lines: [
                    ['account_code' => '1200', 'debit' => $receipt->amount, 'credit' => 0, 'party_type' => Customer::class, 'party_id' => $receipt->customer_id],
                    ['account_code' => $cashAccount->code, 'debit' => 0, 'credit' => $receipt->amount],
                ],
                entryDate: now()->toDateString(),
                userId: $userId
            );

            $cashTransaction = $this->drawerReversalTransaction(
                document: $receipt,
                accountId: (int) $cashAccount->id,
                branchId: $receipt->branch_id ? (int) $receipt->branch_id : null,
                method: (string) $receipt->method,
                amount: (float) $receipt->amount,
                type: 'payment',
                counterpartyType: Customer::class,
                counterpartyId: $receipt->customer_id ? (int) $receipt->customer_id : null,
                reason: $reason,
                userId: $userId,
                originalAffectedDrawer: !empty($receipt->register_shift_id)
            );

            $receipt->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $userId,
                'reversal_reason' => $reason,
                'reversal_journal_entry_id' => $journal->id,
                'reversal_cash_transaction_id' => optional($cashTransaction)->id,
            ])->save();

            return $receipt->fresh();
        });
    }

    public function reverseVendorPayment(VendorPayment $payment, string $reason, int $userId): VendorPayment
    {
        return DB::transaction(function () use ($payment, $reason, $userId) {
            $payment = VendorPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $this->assertNotReversed($payment);
            $this->assertNoStoredAllocations(
                'vendor_payment_allocations',
                'vendor_payment_id',
                (int) $payment->id,
                'payment'
            );

            $cashAccount = $this->cashSync->mapMethodToAccount(
                (string) $payment->method,
                $payment->branch_id ? (int) $payment->branch_id : null,
                true
            );

            $journal = $this->accounting->post(
                branchId: $payment->branch_id,
                memo: "REVERSAL: Vendor payment #{$payment->id} — {$reason}",
                reference: $payment,
                lines: [
                    ['account_code' => $cashAccount->code, 'debit' => $payment->amount, 'credit' => 0],
                    ['account_code' => '2000', 'debit' => 0, 'credit' => $payment->amount, 'party_type' => Vendor::class, 'party_id' => $payment->vendor_id],
                ],
                entryDate: now()->toDateString(),
                userId: $userId
            );

            $originalCashTransaction = $payment->cash_transaction_id
                ? CashTransaction::withTrashed()->find($payment->cash_transaction_id)
                : null;
            $cashTransaction = $this->drawerReversalTransaction(
                document: $payment,
                accountId: (int) $cashAccount->id,
                branchId: $payment->branch_id ? (int) $payment->branch_id : null,
                method: (string) $payment->method,
                amount: (float) $payment->amount,
                type: 'receipt',
                counterpartyType: Vendor::class,
                counterpartyId: $payment->vendor_id ? (int) $payment->vendor_id : null,
                reason: $reason,
                userId: $userId,
                originalAffectedDrawer: $originalCashTransaction && !empty($originalCashTransaction->register_shift_id)
            );

            $payment->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $userId,
                'reversal_reason' => $reason,
                'reversal_journal_entry_id' => $journal->id,
                'reversal_cash_transaction_id' => optional($cashTransaction)->id,
            ])->save();

            return $payment->fresh();
        });
    }

    private function assertNotReversed($document): void
    {
        if ($document->reversed_at || $document->reversal_journal_entry_id) {
            throw ValidationException::withMessages([
                'payment' => ['This party payment has already been reversed.'],
            ]);
        }
    }

    private function assertNoStoredAllocations(
        string $table,
        string $foreignKey,
        int $documentId,
        string $label
    ): void
    {
        // Allocation support is optional in this application. Some production
        // databases do not have allocation tables/models because allocation
        // creation is currently disabled. Avoid resolving the Eloquent
        // relationship unless the underlying feature actually exists.
        if (!Schema::hasTable($table)) {
            return;
        }

        $query = DB::table($table)->where($foreignKey, $documentId);
        if (Schema::hasColumn($table, 'amount')) {
            $query->where('amount', '>', 0);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'payment' => ["This {$label} is allocated to an invoice. Remove its allocations before reversing it."],
            ]);
        }
    }

    private function drawerReversalTransaction(
        $document,
        int $accountId,
        ?int $branchId,
        string $method,
        float $amount,
        string $type,
        string $counterpartyType,
        ?int $counterpartyId,
        string $reason,
        int $userId,
        bool $originalAffectedDrawer
    ): ?CashTransaction {
        if (!$originalAffectedDrawer || !$this->paymentMethods->affectsCashDrawer($branchId, $method)) {
            return null;
        }

        return CashTransaction::create([
            'txn_date' => now()->toDateString(),
            'account_id' => $accountId,
            'branch_id' => $branchId,
            'type' => $type,
            'amount' => round($amount, 2),
            'method' => $method,
            'reference' => 'REV-' . class_basename($document) . '-' . $document->id,
            'note' => 'Party payment reversal: ' . $reason,
            'status' => 'approved',
            'created_by' => $userId,
            'source_type' => get_class($document),
            'source_id' => $document->id,
            'counterparty_type' => $counterpartyType,
            'counterparty_id' => $counterpartyId,
        ]);
    }
}
