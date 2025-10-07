<?php

namespace App\Services;

use App\Models\Purchase;
use Illuminate\Support\Facades\DB;

class PurchaseAdjustmentService
{
    public function __construct(
        protected AccountingService $acc,
    ) {}

    /**
     * Post a balanced delta JE for a changed purchase.
     *
     * $old = ['subtotal'=>..., 'discount'=>..., 'tax'=>..., 'total'=>...]
     * $new = ['subtotal'=>..., 'discount'=>..., 'tax'=>..., 'total'=>...]
     *
     * Rules:
     * - If ANY qty received > 0 on this purchase -> post net goods delta to 5205 (PPV),
     *   else post it to 1400 (Inventory).
     * - Tax delta always to 2105 (Input VAT).
     * - AP delta is the opposite of total delta (to keep balanced).
     */
    public function postBillAdjustment(Purchase $p, array $old, array $new, ?string $date = null): void
    {
        $date ??= now()->toDateString();

        $oldNet = max(0, (float)$old['subtotal'] - (float)$old['discount']);
        $newNet = max(0, (float)$new['subtotal'] - (float)$new['discount']);

        $deltaNet = round($newNet - $oldNet, 2); // goods portion
        $deltaTax = round(((float)$new['tax']) - ((float)$old['tax']), 2);
        $deltaTot = round(((float)$new['total']) - ((float)$old['total']), 2);

        if ($deltaNet == 0 && $deltaTax == 0 && $deltaTot == 0) {
            return; // nothing to post
        }

        $anyReceived = $p->items()->where('received_qty', '>', 0)->exists();

        // choose account for goods delta
        $goodsAccount = $anyReceived ? '5205' : '1400'; // 5205 = Purchase Price Variance (expense), 1400 = Inventory

        // Build lines with proper signs (debit for positive, credit for negative)
        $lines = [];

        // Goods delta
        if ($deltaNet !== 0.0) {
            $lines[] = $this->line($goodsAccount, $deltaNet);
        }

        // Tax delta
        if ($deltaTax !== 0.0) {
            $lines[] = $this->line('2105', $deltaTax); // Input VAT (Recoverable)
        }

        // AP is opposite of total delta
        if ($deltaTot !== 0.0) {
            $lines[] = $this->line('2000', -$deltaTot); // AP: if total up, AP credit (+); our helper flips signs
        }

        // Validate balanced (sum debits == credits)
        $sumD = 0; $sumC = 0;
        foreach ($lines as $l) { $sumD += $l['debit']; $sumC += $l['credit']; }
        if (round($sumD - $sumC, 2) !== 0.0) {
            // As a safety net: if rounding gives a cent off, push to PPV
            $diff = round($sumC - $sumD, 2);
            if ($diff !== 0.0) $lines[] = $this->line('5205', $diff); // small rounding adjustment
        }

        $this->acc->post(
            branchId:  $p->branch_id,
            memo:      "Purchase #{$p->invoice_no} bill adjustment",
            reference: $p,
            lines:     $lines,
            entryDate: $date,
            userId:    auth()->id()
        );
    }

    private function line(string $accountCode, float $amount): array
    {
        // positive => debit, negative => credit
        return [
            'account_code' => $accountCode,
            'debit'  => $amount > 0 ? abs($amount) : 0,
            'credit' => $amount < 0 ? abs($amount) : 0,
        ];
    }
}
