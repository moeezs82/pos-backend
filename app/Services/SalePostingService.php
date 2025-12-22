<?php

namespace App\Services;

use App\Models\{Customer, Sale, StockMovement};
use Illuminate\Support\Facades\DB;

class SalePostingService
{
    public function __construct(
        protected AccountingService $acc,
        protected InventoryValuationService $val
    ) {}

    public function postSale(Sale $sale): void
    {
        // Build postings:
        // AR (or Cash later on receipt)    DR total
        // Sales Revenue                     CR subtotal - discount (or post discount to expense)
        // Sales Tax Payable                 CR tax
        // COGS                              DR cogs
        // Inventory                          CR cogs

        $subtotalNet = $sale->subtotal - $sale->discount + $sale->delivery;
        $revenue     = max($subtotalNet, 0);
        $tax         = $sale->tax;
        $total       = $sale->total;

        // Compute COGS from items (using avg cost snapshot)
        $cogs = 0;
        foreach ($sale->items as $it) {
            $cogs += $it->line_cost;
        }

        $lines = [
            ['account_code' => '1200', 'debit' => $total,   'credit' => 0, 'party_type' => Customer::class, 'party_id' => $sale->customer_id],            // AR
            ['account_code' => '4000', 'debit' => 0,        'credit' => $revenue],     // Sales
        ];
        if ($tax > 0)  $lines[] = ['account_code' => '2100', 'debit' => 0, 'credit' => $tax]; // Tax payable
        if ($cogs > 0) {
            $lines[] = ['account_code' => '5100', 'debit' => $cogs, 'credit' => 0];     // COGS
            $lines[] = ['account_code' => '1400', 'debit' => 0,   'credit' => $cogs];  // Inventory
        }

        $this->acc->post($sale->branch_id, "Sale #{$sale->invoice_no}", $sale, $lines, $sale->invoice_date, $sale->created_by);
    }

    public function deductStockAndStampCosts(Sale $sale): void
    {
        foreach ($sale->items as $it) {
            $avg = $this->val->avgCost($it->product_id, $sale->branch_id);
            $lineCost = round($avg * $it->quantity, 2);

            $it->update(['unit_cost' => $avg, 'line_cost' => $lineCost]);

            DB::table('product_stocks')
                ->where('product_id', $it->product_id)
                ->where('branch_id',  $sale->branch_id)
                ->decrement('quantity', $it->quantity);

            StockMovement::create([
                'product_id' => $it->product_id,
                'branch_id' => $sale->branch_id,
                'type' => 'sale',
                'quantity' => -$it->quantity,
                'reference' => $sale->invoice_no,
            ]);
        }

        $cogs = $sale->items()->sum('line_cost');
        $sale->update([
            'cogs' => $cogs,
            'gross_profit' => $sale->total - $cogs,
        ]);
    }
}
