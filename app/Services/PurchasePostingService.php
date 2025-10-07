<?php

namespace App\Services;

use App\Models\{Purchase, PurchaseItem};
use Illuminate\Support\Facades\DB;

class PurchasePostingService
{
    public function __construct(
        protected AccountingService $acc,
        protected InventoryValuationWriteService $valWrite,
    ) {}

    /**
     * Post the vendor bill (invoice) for a purchase.
     * JE (simple 1-step):
     *   Dr Inventory       = subtotal - discount
     *   Dr Input VAT       = tax
     *   Cr Accounts Payable= total
     */
    public function postVendorBill(Purchase $p, ?string $entryDate=null): void
    {
        $subtotalNet = max(0, (float)$p->subtotal - (float)$p->discount);
        $tax         = (float)$p->tax;
        $total       = (float)$p->total;

        // Guard: do not double post the same bill
        if ($p->journalEntries()->exists()) return;

        $lines = [
            ['account_code' => '1400', 'debit' => $subtotalNet, 'credit' => 0], // Inventory
            ['account_code' => '2105', 'debit' => $tax,         'credit' => 0], // Input VAT
            ['account_code' => '2000', 'debit' => 0,           'credit' => $total], // AP
        ];

        $this->acc->post(
            $p->branch_id,
            "Vendor Bill #{$p->invoice_no}",
            $p,
            $lines,
            $entryDate ?? now()->toDateString(),
            $p->created_by
        );
    }

    /**
     * Receive items (partial/full), update stock + avg cost, and record stock movements.
     * Assumes you invoice now (common in POS). If you want GRN/GRNI:
     *  - call $this->postGRN(...) at receipt, and $this->clearGRNIOnBill(...) when invoice arrives.
     */
    public function receiveItemsAndValue(Purchase $p, array $receiveRows): Purchase
    {
        // $receiveRows = [['item_id'=>..., 'receive_qty'=>...], ...]
        return DB::transaction(function () use ($p, $receiveRows) {
            foreach ($receiveRows as $row) {
                /** @var PurchaseItem $it */
                $it = $p->items()->lockForUpdate()->findOrFail($row['item_id']);
                $qtyToReceive = (int) $row['receive_qty'];
                if ($qtyToReceive <= 0) continue;

                $remaining = (int)$it->quantity - (int)$it->received_qty;
                $rcv = min($qtyToReceive, max(0, $remaining));
                if ($rcv <= 0) continue;

                // moving-average cost update (per branch)
                $this->valWrite->receivePurchase(
                    productId: $it->product_id,
                    branchId:  $p->branch_id,
                    receiveQty:$rcv,
                    unitPrice: (float)$it->price,
                    ref:       $p->invoice_no
                );

                $it->increment('received_qty', $rcv);
            }

            // set receive_status
            $totalQty     = (int) $p->items()->sum('quantity');
            $totalRcvQty  = (int) $p->items()->sum('received_qty');
            $status = match(true) {
                $totalRcvQty === 0                => 'ordered',
                $totalRcvQty < $totalQty          => 'partial',
                $totalRcvQty >= $totalQty && $totalQty>0 => 'received',
                default                           => 'ordered',
            };

            $p->update(['receive_status' => $status]);

            return $p->fresh(['items']);
        });
    }

    /* Optional GRN/GRNI variant (commented; enable if you want 2-step):
    public function postGRN(Purchase $p, array $receiveRows, ?string $date=null): void
    {
        // DR Inventory (received value)
        // CR GRNI (accrual)
        // Then, at invoice postVendorBill(), DR GRNI, CR AP
    }

    public function clearGRNIOnBill(Purchase $p, ?string $date=null): void
    {
        // DR GRNI
        // CR AP
    }
    */
}
