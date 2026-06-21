<?php

namespace App\Services;

use App\Models\{Customer, ProductStock, Sale, StockMovement};
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

        $subtotalNet = round((float)$sale->subtotal - (float)$sale->discount + (float)$sale->delivery, 2);
        $tax         = round((float)$sale->tax, 2);
        $total       = round((float)$sale->total, 2);

        // Compute COGS from items (using avg cost snapshot)
        $cogs = 0;
        foreach ($sale->items as $it) {
            $cogs += (float)$it->line_cost;
        }
        $cogs = round($cogs, 2);

        $lines = [];

        if ($total !== 0.0) {
            $lines[] = $this->signedLine('1200', $total, Customer::class, $sale->customer_id); // AR
        }

        if ($subtotalNet > 0) {
            $lines[] = ['account_code' => '4000', 'debit' => 0, 'credit' => $subtotalNet]; // Sales
        } elseif ($subtotalNet < 0) {
            $lines[] = [
                'account_code' => config('accounts.sales_returns_account', '4000'),
                'debit' => abs($subtotalNet),
                'credit' => 0,
            ]; // Sales return / revenue reversal
        }

        if ($tax !== 0.0) {
            $lines[] = $this->signedLine('2100', -$tax); // Tax payable: positive tax is credit, negative tax is debit
        }

        if ($cogs > 0) {
            $lines[] = ['account_code' => '5100', 'debit' => $cogs, 'credit' => 0]; // COGS
            $lines[] = ['account_code' => '1400', 'debit' => 0, 'credit' => $cogs]; // Inventory
        } elseif ($cogs < 0) {
            $lines[] = ['account_code' => '1400', 'debit' => abs($cogs), 'credit' => 0]; // Returned inventory
            $lines[] = ['account_code' => '5100', 'debit' => 0, 'credit' => abs($cogs)]; // Reverse COGS
        }

        if (empty($lines)) {
            return;
        }

        $this->acc->post($sale->branch_id, "Sale #{$sale->invoice_no}", $sale, $lines, $sale->invoice_date, $sale->created_by);

        app(DeliveryBoyLedgerService::class)->postSaleAssignment($sale);
    }

    public function deductStockAndStampCosts(Sale $sale): void
    {
        foreach ($sale->items as $it) {
            $avg = $this->val->avgCost($it->product_id, $sale->branch_id);
            $lineCost = round($avg * $it->quantity, 2);

            $it->update(['unit_cost' => $avg, 'line_cost' => $lineCost]);

            DB::table('product_stocks')
                ->where('product_id', $it->product_id)
                ->where('branch_id', $sale->branch_id)
                ->decrement('quantity', $it->quantity, [
                    'updated_at' => now(),
                ]);

            StockMovement::create([
                'product_id' => $it->product_id,
                'branch_id' => $sale->branch_id,
                'type' => $it->quantity < 0 ? 'return' : 'sale',
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

    private function signedLine(string $accountCode, float $amount, $partyType = null, $partyId = null): array
    {
        return [
            'account_code' => $accountCode,
            'debit' => $amount > 0 ? abs($amount) : 0,
            'credit' => $amount < 0 ? abs($amount) : 0,
            'party_type' => $partyType,
            'party_id' => $partyId,
        ];
    }
}
