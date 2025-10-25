<?php

namespace App\Services;

use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use App\Services\CashSyncService;
use App\Services\AccountingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VendorPaymentService
{
    protected CashSyncService $cashSync;
    protected AccountingService $accounting;

    public function __construct(CashSyncService $cashSync, AccountingService $accounting)
    {
        $this->cashSync = $cashSync;
        $this->accounting = $accounting;
    }

    /**
     * Create vendor payment, post GL, and create allocations.
     *
     * NOTE: caller should handle DB transaction if needed.
     *
     * @param array $data  keys:
     *   vendor_id, branch_id, paid_at?, method, amount, reference?, note?,
     *   allocations? => [ ['purchase_id'=>int, 'amount'=>float], ... ]
     *
     * @return VendorPayment
     *
     * @throws ValidationException
     */
    public function create(array $data, $isPostAccount = true): VendorPayment
    {
        $v = Validator::make($data, [
            'vendor_id' => 'nullable|exists:vendors,id',
            'branch_id' => 'nullable|exists:branches,id',
            'paid_at'   => 'nullable|date',
            'method'    => 'required|string|in:cash,bank,card,wallet',
            'amount'    => 'required|numeric|min:0.01',
            'memo' => 'nullable|string',
            'reference' => 'nullable|string',
            'note'      => 'nullable|string',
            // 'allocations'                 => 'nullable|array|min:1',
            // 'allocations.*.purchase_id'   => 'required|integer|exists:purchases,id',
            // 'allocations.*.amount'        => 'required|numeric|min:0.01',
        ]);

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        // create vendor payment
        $vp = VendorPayment::create([
            'vendor_id'  => $data['vendor_id'],
            'purchase_id'  => $data['purchase_id'] ?? null,
            'branch_id'  => $data['branch_id'],
            'paid_at'    => $data['paid_at'] ?? now()->toDateString(),
            'method'     => $data['method'],
            'amount'     => round($data['amount'], 2),
            'reference'  => $data['reference'] ?? null,
            'note'       => $data['note'] ?? null,
            'created_by' => auth()->id(),
        ]);

        // Post double entry: DR AP (2000), CR Cash/Bank (from cashSync)
        $cashAccount = $this->cashSync->mapMethodToAccount($vp->method, $vp->branch_id);

        if ($isPostAccount) {
            $this->accounting->post(
                branchId: $vp->branch_id,
                memo: $data['memo'] ?? "Vendor payment #{$vp->id}",
                reference: $vp,
                lines: [
                    ['account_code' => '2000',               'debit' => $vp->amount, 'credit' => 0, 'party_type' => Vendor::class, 'party_id' => $vp->vendor_id], // reduce AP
                    ['account_code' => $cashAccount->code,   'debit' => 0,           'credit' => $vp->amount], // cash/bank credit
                ],
                entryDate: $vp->paid_at,
                userId: $vp->created_by
            );
        }

        // Save allocations (if provided). Caller may pass allocations or we can create allocations
        // for a single purchase (e.g. the purchase we just created) outside of this method.
        // foreach (($data['allocations'] ?? []) as $al) {
        //     VendorPaymentAllocation::create([
        //         'vendor_payment_id' => $vp->id,
        //         'purchase_id'       => $al['purchase_id'],
        //         'amount'            => round($al['amount'], 2),
        //     ]);
        // }

        return $vp;
    }
}
