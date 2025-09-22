<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashTransaction;
use App\Models\PaymentMethodAccount;
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

        return $txn;
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
}
