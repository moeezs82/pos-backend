<?php

namespace App\Services;

use App\Models\Receipt;
use App\Models\ReceiptAllocation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerPaymentService
{
    protected CashSyncService $cashSync;
    protected AccountingService $accounting;

    public function __construct(CashSyncService $cashSync, AccountingService $accounting)
    {
        $this->cashSync = $cashSync;
        $this->accounting = $accounting;
    }

    /**
     * Create a customer receipt, post GL (DR Cash/Bank, CR AR), and create allocations.
     *
     * $data keys:
     *  - customer_id, branch_id, received_at?, method, amount, reference?, note?,
     *  - allocations? => [ ['sale_id'=>int, 'amount'=>float], ... ]
     *
     * @throws ValidationException
     */
    public function create(array $data, $isPostAccount = true): Receipt
    {
        $v = Validator::make($data, [
            'customer_id' => 'required|exists:customers,id',
            'branch_id'   => 'required|exists:branches,id',
            'received_at' => 'nullable|date',
            'method'      => 'required|string|in:cash,bank,card,wallet',
            'amount'      => 'required|numeric|min:0.01',
            'reference'   => 'nullable|string',
            'note'        => 'nullable|string',
            'allocations' => 'array',
            'allocations.*.sale_id' => 'required_with:allocations|exists:sales,id',
            'allocations.*.amount'  => 'required_with:allocations|numeric|min:0.01',
        ]);

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        // create receipt (DB model Receipt assumed)
        $r = Receipt::create([
            'customer_id' => $data['customer_id'],
            'branch_id'   => $data['branch_id'],
            'received_at' => $data['received_at'] ?? now()->toDateString(),
            'method'      => $data['method'],
            'amount'      => round($data['amount'], 2),
            'reference'   => $data['reference'] ?? null,
            'note'        => $data['note'] ?? null,
            'created_by'  => auth()->id(),
        ]);

        // Post double entry: DR Cash/Bank, CR Accounts Receivable (AR)
        // Map cash/bank account using cashSync (same pattern as vendor)
        $cashAccount = $this->cashSync->mapMethodToAccount($r->method, $r->branch_id);

        if ($isPostAccount) {
            // Use account code for AR (choose consistent code with your chart)
            // I use '1200' as Accounts Receivable (adjust if your chart differs)
            $this->accounting->post(
                branchId: $r->branch_id,
                memo: "Customer receipt #{$r->id}",
                reference: $r,
                lines: [
                    ['account_code' => $cashAccount->code, 'debit' => $r->amount, 'credit' => 0], // cash/bank debit
                    ['account_code' => '1200',               'debit' => 0,         'credit' => $r->amount], // AR credit
                ],
                entryDate: $r->received_at,
                userId: $r->created_by
            );
        }

        // Allocations: apply receipt to sales (if provided); otherwise caller can allocate later
        foreach (($data['allocations'] ?? []) as $al) {
            ReceiptAllocation::create([
                'receipt_id' => $r->id,
                'sale_id'    => $al['sale_id'],
                'amount'     => round($al['amount'], 2),
            ]);
        }

        return $r;
    }
}
