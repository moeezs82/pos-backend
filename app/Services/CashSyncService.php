<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashTransaction;
use App\Models\PaymentMethodAccount;
use App\Models\PurchaseClaimReceipt;
use App\Models\SaleReturnRefund;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CashSyncService
{
    public function mapMethodToAccount(string $method, ?int $branchId = null): Account
    {
        $q = PaymentMethodAccount::query()->where('method', $method);

        // 1) Try exact branch first
        if ($branchId) {
            $map = (clone $q)->where('branch_id', $branchId)->first();
            if ($map) return $map->account;
        }

        // 2) Fallback to global (branch_id NULL)
        $map = (clone $q)->whereNull('branch_id')->first();
        if ($map) return $map->account;

        throw ValidationException::withMessages([
            'account_id' => "No account mapping found for payment method [$method]" . ($branchId ? " (branch $branchId)" : '') . "."
        ]);
    }

    /** Sales payment -> receipt */
    public function syncFromPayment($payment, ?int $branchId = null): CashTransaction
    {
        $account = $this->mapMethodToAccount($payment->method, $branchId);
        $txn = CashTransaction::create([
            'txn_date'   => $payment->created_at ?? now()->toDateString(),
            'account_id' => $account->id,
            'branch_id'  => $branchId,
            'type'       => 'receipt',
            'amount'     => $payment->amount,
            'method'     => $payment->method,
            'reference'  => $payment->reference ?: 'Sale#' . $payment->sale_id,
            'note'       => 'Sales payment',
            'status'     => 'approved',
            'created_by' => $payment->received_by ?: Auth::id(),
            'source_type' => get_class($payment),
            'source_id'  => $payment->id,
            'counterparty_type' => \App\Models\Customer::class,
            'counterparty_id'   => optional($payment->sale)->customer_id,
            'voucher_no' => null,
        ]);
        $payment->cash_transaction_id = $txn->id;
        $payment->save();

        return $txn;
    }

    /** Purchase payment -> payment (outflow) */
    public function syncFromPurchasePayment($pp, ?int $branchId = null): CashTransaction
    {
        $method  = $pp->method ?: 'cash';
        $account = $this->mapMethodToAccount($method, $branchId);

        $txn = CashTransaction::create([
            'txn_date'   => optional($pp->paid_at)->toDateString() ?? now()->toDateString(),
            'account_id' => $account->id,
            'branch_id'  => $branchId,
            'type'       => 'payment',
            'amount'     => $pp->amount,
            'method'     => $method,
            'reference'  => $pp->tx_ref ?: 'Purchase#' . $pp->purchase_id,
            'note'       => 'Purchase payment',
            'status'     => 'approved',
            'created_by' => Auth::id(),
            'source_type' => get_class($pp),
            'source_id'  => $pp->id,
            'counterparty_type' => \App\Models\Vendor::class,
            'counterparty_id'   => optional($pp->purchase)->vendor_id,
            'voucher_no' => null,
        ]);

        $pp->cash_transaction_id = $txn->id;
        $pp->save();

        $this->postAPSettlementToGL($pp, $account->code);

        return $txn;
    }

    private function postAPSettlementToGL($pp, string $cashBankAccountCode): void
    {
        app(\App\Services\AccountingService::class)->post(
            branchId: optional($pp->purchase)->branch_id,
            memo: "Purchase Payment #{$pp->id} for {$pp->purchase_id}",
            reference: $pp,
            lines: [
                ['account_code' => '2000',              'debit' => (float)$pp->amount, 'credit' => 0], // AP
                ['account_code' => $cashBankAccountCode, 'debit' => 0,                  'credit' => (float)$pp->amount], // Cash/Bank
            ],
            entryDate: optional($pp->paid_at)->toDateString() ?? now()->toDateString(),
            userId: auth()->id()
        );
    }

    public function resync(CashTransaction $txn, array $fields): CashTransaction
    {
        $txn->fill($fields);
        $txn->save();
        return $txn->fresh();
    }

    public function remove(CashTransaction $txn): void
    {
        $txn->delete();
    }

    /** Opening balance for an account/branch up to (but excluding) $dateFrom */
    public function openingBalance(int $accountId, ?int $branchId, string $dateFrom): float
    {
        $q = CashTransaction::query()->where('account_id', $accountId)
            ->where('status', 'approved')
            ->where('txn_date', '<', $dateFrom);

        if ($branchId) $q->where('branch_id', $branchId);

        $in  = (clone $q)->whereIn('type', ['receipt', 'transfer_in'])->sum('amount');
        $out = (clone $q)->whereIn('type', ['payment', 'expense', 'transfer_out'])->sum('amount');

        return round($in - $out, 2);
    }

    public function createExpense(array $data): \App\Models\CashTransaction
    {
        // You can pass either account_id OR method (method will map to account).
        $accountId = $data['account_id'] ?? null;
        $branchId  = $data['branch_id'] ?? null;

        if (!$accountId) {
            if (empty($data['method'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'method' => 'Either account_id or method is required for an expense.'
                ]);
            }
            $accountId = $this->mapMethodToAccount($data['method'], $branchId)->id;
        }

        return \App\Models\CashTransaction::create([
            'txn_date'   => $data['txn_date'] ?? now()->toDateString(),
            'account_id' => $accountId,
            'branch_id'  => $branchId,
            'type'       => 'expense',
            'amount'     => $data['amount'],
            'method'     => $data['method'] ?? null,
            'reference'  => $data['reference'] ?? null,   // e.g. invoice no, receipt no.
            'note'       => $data['note'] ?? 'Expense',
            'status'     => $data['status'] ?? 'approved',
            'created_by' => auth()->id(),
            // Optional: link to a vendor/customer/etc. if provided
            'counterparty_type' => $data['counterparty_type'] ?? null, // e.g., App\Models\Vendor
            'counterparty_id'   => $data['counterparty_id'] ?? null,
            // Not linked to a source doc; leave source_type/source_id null
        ]);
    }

    public function postSaleReturnRefund(array $data): \App\Models\CashTransaction
    {
        // $data: saleReturn (model), amount, method, reference?, date?, branch_id?
        $sr = $data['saleReturn'];
        $amount = (float)$data['amount'];
        if ($amount <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount' => 'Amount must be > 0']);
        }

        // Optional: prevent duplicate posting for same return if you want only one
        $exists = \App\Models\CashTransaction::query()
            ->where('source_type', \App\Models\SaleReturn::class)
            ->where('source_id', $sr->id)
            ->where('type', 'payment')
            ->exists();
        if ($exists) {
            throw \Illuminate\Validation\ValidationException::withMessages(['cash' => 'Refund already posted for this return.']);
        }

        $method  = $data['method'] ?? 'cash';
        $branchId = $data['branch_id'] ?? $sr->branch_id;
        $account = $this->mapMethodToAccount($method, $branchId);

        return \App\Models\CashTransaction::create([
            'txn_date'   => ($data['date'] ?? now())->toDateString(),
            'account_id' => $account->id,
            'branch_id'  => $branchId,
            'type'       => 'payment', // money OUT
            'amount'     => $amount,
            'method'     => $method,
            'reference'  => $data['reference'] ?? $sr->return_no,
            'note'       => 'Sale return refund',
            'status'     => 'approved',
            'created_by' => auth()->id(),
            'source_type' => \App\Models\SaleReturn::class,
            'source_id'  => $sr->id,
            'counterparty_type' => \App\Models\Customer::class,
            'counterparty_id'   => $sr->customer_id,
            'voucher_no' => null,
            'meta'       => $data['meta'] ?? null,
        ]);
    }

    public function postPurchaseClaimReceipt(array $data): \App\Models\CashTransaction
    {
        // $data: claim (model), amount, method, reference?, date?, branch_id?
        $claim = $data['claim'];
        $amount = (float)$data['amount'];
        if ($amount <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount' => 'Amount must be > 0']);
        }

        // Optional: prevent duplicate posting if you want only one
        $exists = \App\Models\CashTransaction::query()
            ->where('source_type', \App\Models\PurchaseClaim::class)
            ->where('source_id', $claim->id)
            ->where('type', 'receipt')
            ->exists();
        if ($exists) {
            throw \Illuminate\Validation\ValidationException::withMessages(['cash' => 'Receipt already posted for this claim.']);
        }

        $method  = $data['method'] ?? 'cash';
        $branchId = $data['branch_id'] ?? $claim->branch_id;
        $account = $this->mapMethodToAccount($method, $branchId);

        return \App\Models\CashTransaction::create([
            'txn_date'   => ($data['date'] ?? now())->toDateString(),
            'account_id' => $account->id,
            'branch_id'  => $branchId,
            'type'       => 'receipt', // money IN
            'amount'     => $amount,
            'method'     => $method,
            'reference'  => $data['reference'] ?? $claim->claim_no,
            'note'       => 'Purchase claim receipt',
            'status'     => 'approved',
            'created_by' => auth()->id(),
            'source_type' => \App\Models\PurchaseClaim::class,
            'source_id'  => $claim->id,
            'counterparty_type' => \App\Models\Vendor::class,
            'counterparty_id'   => $claim->vendor_id,
            'voucher_no' => null,
            'meta'       => $data['meta'] ?? null,
        ]);
    }

    public function syncFromSaleReturnRefund(SaleReturnRefund $refund, ?int $branchId = null): CashTransaction
    {
        $method  = $refund->method ?: 'cash';
        $account = $this->mapMethodToAccount($method, $branchId);

        $sr  = $refund->saleReturn; // has branch_id, customer_id
        $txn = CashTransaction::create([
            'txn_date'   => optional($refund->refunded_at)->toDateString() ?? now()->toDateString(),
            'account_id' => $account->id,
            'branch_id'  => $branchId ?? optional($sr)->branch_id,
            'type'       => 'payment', // MONEY OUT (refund to customer)
            'amount'     => $refund->amount,
            'method'     => $method,
            'reference'  => $refund->reference ?: 'Return#' . $refund->sale_return_id,
            'note'       => 'Sale return refund',
            'status'     => 'approved',
            'created_by' => $refund->created_by ?: Auth::id(),
            'source_type' => get_class($refund),
            'source_id'  => $refund->id,
            'counterparty_type' => \App\Models\Customer::class,
            'counterparty_id'   => optional($sr)->customer_id,
            'voucher_no' => null,
        ]);

        $refund->cash_transaction_id = $txn->id;
        $refund->save();

        return $txn;
    }

    public function syncFromPurchaseClaimReceipt(PurchaseClaimReceipt $receipt, ?int $branchId = null): CashTransaction
    {
        $method  = $receipt->method ?: 'cash';
        $account = $this->mapMethodToAccount($method, $branchId);

        $pc  = $receipt->purchaseClaim; // has branch_id, vendor_id
        $txn = CashTransaction::create([
            'txn_date'   => optional($receipt->received_at)->toDateString() ?? now()->toDateString(),
            'account_id' => $account->id,
            'branch_id'  => $branchId ?? optional($pc)->branch_id,
            'type'       => 'receipt', // MONEY IN (vendor pays back)
            'amount'     => $receipt->amount,
            'method'     => $method,
            'reference'  => $receipt->reference ?: 'Claim#' . $receipt->purchase_claim_id,
            'note'       => 'Purchase claim receipt',
            'status'     => 'approved',
            'created_by' => $receipt->created_by ?: Auth::id(),
            'source_type' => get_class($receipt),
            'source_id'  => $receipt->id,
            'counterparty_type' => \App\Models\Vendor::class,
            'counterparty_id'   => optional($pc)->vendor_id,
            'voucher_no' => null,
        ]);

        $receipt->cash_transaction_id = $txn->id;
        $receipt->save();

        return $txn;
    }
}
