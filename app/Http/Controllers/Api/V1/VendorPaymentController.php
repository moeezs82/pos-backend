<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use App\Services\AccountingService;
use App\Services\CashSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorPaymentController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'vendor_id'  => 'required|exists:vendors,id',
            'branch_id'  => 'required|exists:branches,id',
            'paid_at'    => 'nullable|date',
            'method'     => 'required|string|in:cash,bank,card,wallet',
            'amount'     => 'required|numeric|min:0.01',
            'reference'  => 'nullable|string',
            'note'       => 'nullable|string',
            // Optional UI allocations; purely informational
            'allocations' => 'array',
            'allocations.*.purchase_id' => 'required_with:allocations|exists:purchases,id',
            'allocations.*.amount'      => 'required_with:allocations|numeric|min:0.01',
        ]);

        return DB::transaction(function () use ($data) {
            $vp = VendorPayment::create([
                'vendor_id'  => $data['vendor_id'],
                'branch_id'  => $data['branch_id'],
                'paid_at'    => $data['paid_at'] ?? now()->toDateString(),
                'method'     => $data['method'],
                'amount'     => round($data['amount'], 2),
                'reference'  => $data['reference'] ?? null,
                'note'       => $data['note'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // (A) Post double-entry to GL: DR AP (2000), CR Cash/Bank (1000/1010...)
            // Map method -> cash/bank account code (reuse your CashSyncService if you like)
            $cashAccount = app(CashSyncService::class)->mapMethodToAccount($vp->method, $vp->branch_id);
            app(AccountingService::class)->post(
                branchId: $vp->branch_id,
                memo: "Vendor payment #{$vp->id}",
                reference: $vp,
                lines: [
                    ['account_code' => '2000',               'debit' => $vp->amount, 'credit' => 0, 'party_type' => Vendor::class, 'party_id' => $vp->vendor_id], // reduce AP
                    ['account_code' => $cashAccount->code,   'debit' => 0,           'credit' => $vp->amount], // cash/bank credit
                ],
                entryDate: $vp->paid_at,
                userId: $vp->created_by
            );

            // (B) Optional: save allocations for UI (no accounting impact)
            foreach (($data['allocations'] ?? []) as $al) {
                VendorPaymentAllocation::create([
                    'vendor_payment_id' => $vp->id,
                    'purchase_id'       => $al['purchase_id'],
                    'amount'            => round($al['amount'], 2),
                ]);
            }

            return response()->json($vp->load('allocations'), 201);
        });
    }
}
