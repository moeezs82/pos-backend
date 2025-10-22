<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Sale;

class SaleAdjustmentService
{
    public function __construct(
        protected AccountingService $acc,
    ) {}

    /**
     * Post a balanced delta JE for a changed sale.
     *
     * $old = ['subtotal'=>..., 'discount'=>..., 'tax'=>..., 'total'=>...]
     * $new = ['subtotal'=>..., 'discount'=>..., 'tax'=>..., 'total'=>...]
     *
     * Rules (matching your purchase pattern, but mirrored for sales):
     * - Revenue delta goes to 4000 (Sales Revenue).
     * - Output VAT delta goes to 2205 (Output VAT / Sales Tax Payable).
     * - AR delta is opposite of total delta (account 1200).
     *
     * This function assumes items were NOT changed (only totals changed). If quantities/prices
     * change, you will need a more complex reversal of COGS/Inventory JEs.
     */
    public function postSaleAdjustment(Sale $s, array $old, array $new, ?string $date = null): void
    {
        $date ??= now()->toDateString();

        $oldNet = max(0, (float)$old['subtotal'] - (float)$old['discount']);
        $newNet = max(0, (float)$new['subtotal'] - (float)$new['discount']);

        $deltaNet = round($newNet - $oldNet, 2); // revenue portion change
        $deltaTax = round(((float)$new['tax']) - ((float)$old['tax']), 2);
        $deltaTot = round(((float)$new['total']) - ((float)$old['total']), 2);

        if ($deltaNet == 0 && $deltaTax == 0 && $deltaTot == 0) {
            return; // nothing to post
        }

        // Build lines
        $lines = [];

        // Revenue delta: positive => increase revenue (credit), negative => decrease revenue (debit)
        if ($deltaNet !== 0.0) {
            // Our helper expects positive => debit, negative => credit; to make revenue (credit on increase)
            // we pass negative deltaNet so helper flips signs correctly.
            $lines[] = $this->line('4000', -$deltaNet); // 4000 = Sales Revenue
        }

        // Tax delta (Output VAT / Sales Tax Payable). Positive deltaTax => liability increases (credit)
        if ($deltaTax !== 0.0) {
            $lines[] = $this->line('2205', -$deltaTax); // 2205 = Output VAT / Sales Tax Payable
        }

        // AR is opposite of total delta: if total increased, AR should increase (debit AR)
        if ($deltaTot !== 0.0) {
            // AR is an asset; increase => debit; our helper uses positive => debit so pass deltaTot
            $lines[] = $this->line('1200', $deltaTot, partyType: Customer::class, partyId: $s->customer_id); // 1200 = Accounts Receivable
        }

        // Validate balanced; if tiny rounding issues occur push small difference to Revenue (4000)
        $sumD = 0; $sumC = 0;
        foreach ($lines as $l) { $sumD += $l['debit']; $sumC += $l['credit']; }
        if (round($sumD - $sumC, 2) !== 0.0) {
            $diff = round($sumC - $sumD, 2);
            if ($diff !== 0.0) {
                // push to revenue as an offset
                $lines[] = $this->line('4000', -$diff);
            }
        }

        $this->acc->post(
            branchId:  $s->branch_id,
            memo:      "Sale #{$s->invoice_no} adjustment",
            reference: $s,
            lines:     $lines,
            entryDate: $date,
            userId:    auth()->id()
        );
    }

    private function line(string $accountCode, float $amount, $partyType=null, $partyId=null): array
    {
        // positive => debit, negative => credit
        return [
            'account_code' => $accountCode,
            'debit'  => $amount > 0 ? abs($amount) : 0,
            'credit' => $amount < 0 ? abs($amount) : 0,
            'party_type' => $partyType,
            'partyId' => $partyId
        ];
    }
}
