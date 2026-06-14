<?php

namespace App\Services;

use App\Models\DeliveryBoyReceived;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\User;

class DeliveryBoyLedgerService
{
    public const ACCOUNT_CODE = '1210';

    public function __construct(
        protected AccountingService $accounting,
        protected CashSyncService $cashSync,
    ) {}

    /**
     * Delivery-boy balance is an operational custody ledger, not a replacement
     * for customer receivable.
     *
     * Customer AR/sale accounting remains exactly as SalePostingService posts it.
     * This service only adds party-tracked postings on account 1210 so the system
     * knows how much cash/value is assigned to a delivery boy and how much was
     * received back from him.
     *
     * To avoid touching customer AR, cash, sales, tax, or P&L, every custody entry
     * is balanced against the same account without a party. Reports read only the
     * party side: party debit = amount to receive, party credit = received/settled.
     */
    public function postSaleAssignment(Sale $sale, ?float $amount = null, ?string $date = null): void
    {
        if (!$sale->delivery_boy_id) {
            return;
        }

        $amount ??= (float) $sale->total;
        $this->postCustodyMovement($sale, (int) $sale->delivery_boy_id, round($amount, 2), $date, 'delivery boy assignment');
    }

    /**
     * Post only the total delta to delivery-boy custody after a sale total changes.
     */
    public function postSaleTotalAdjustment(Sale $sale, float $deltaTotal, ?string $date = null): void
    {
        if (!$sale->delivery_boy_id) {
            return;
        }

        $this->postCustodyMovement($sale, (int) $sale->delivery_boy_id, round($deltaTotal, 2), $date, 'delivery boy sale total adjustment');
    }

    /**
     * Move custody balance when the delivery boy on a sale is changed.
     */
    public function postSaleDeliveryBoyChange(Sale $sale, ?int $oldDeliveryBoyId, ?int $newDeliveryBoyId, ?string $date = null): void
    {
        $oldDeliveryBoyId = $oldDeliveryBoyId ? (int) $oldDeliveryBoyId : null;
        $newDeliveryBoyId = $newDeliveryBoyId ? (int) $newDeliveryBoyId : null;

        if ($oldDeliveryBoyId === $newDeliveryBoyId) {
            return;
        }

        $amount = round((float) $sale->total, 2);
        if (abs($amount) < 0.005) {
            return;
        }

        if ($oldDeliveryBoyId && $newDeliveryBoyId) {
            $this->accounting->post(
                branchId: $sale->branch_id,
                memo: "Sale #{$sale->invoice_no} delivery boy changed",
                reference: $sale,
                lines: [
                    $this->partyLine($amount, $newDeliveryBoyId),
                    $this->partyLine(-$amount, $oldDeliveryBoyId),
                ],
                entryDate: $date ?? now()->toDateString(),
                userId: auth()->id()
            );

            return;
        }

        if ($oldDeliveryBoyId) {
            $this->postCustodyMovement($sale, $oldDeliveryBoyId, -$amount, $date, 'delivery boy removed');
            return;
        }

        if ($newDeliveryBoyId) {
            $this->postCustodyMovement($sale, $newDeliveryBoyId, $amount, $date, 'delivery boy assigned');
        }
    }

    /**
     * Customer receipt must remain customer flow. This method is intentionally a
     * no-op and is kept only to avoid breaking older callers during deployment.
     */
    public function postCustomerReceiptForDeliverySale(Receipt $receipt, Sale $sale): void
    {
        return;
    }

    /**
     * Delivery boy handed cash to shop. Keep DeliveryBoyReceived as the document,
     * and reduce only the delivery-boy custody balance.
     */
    public function postDeliveryBoyReceived(DeliveryBoyReceived $received, string $method = 'cash'): void
    {
        $amount = round((float) $received->amount, 2);
        if (abs($amount) < 0.005) {
            return;
        }

        $this->accounting->post(
            branchId: $received->branch_id,
            memo: "Delivery boy cash received #{$received->id}",
            reference: $received,
            lines: [
                $this->contraLine($amount),
                $this->partyLine(-$amount, (int) $received->user_id),
            ],
            entryDate: $received->created_at?->toDateString() ?? now()->toDateString(),
            userId: auth()->id()
        );
    }

    private function postCustodyMovement(Sale $sale, int $deliveryBoyId, float $amount, ?string $date, string $reason): void
    {
        if (abs($amount) < 0.005) {
            return;
        }

        $this->accounting->post(
            branchId: $sale->branch_id,
            memo: "Sale #{$sale->invoice_no} {$reason}",
            reference: $sale,
            lines: [
                $this->partyLine($amount, $deliveryBoyId),
                $this->contraLine(-$amount),
            ],
            entryDate: $date ?? $sale->invoice_date ?? $sale->created_at?->toDateString() ?? now()->toDateString(),
            userId: $sale->created_by ?? auth()->id()
        );
    }

    private function partyLine(float $amount, int $deliveryBoyId): array
    {
        return $this->line(self::ACCOUNT_CODE, $amount, User::class, $deliveryBoyId);
    }

    private function contraLine(float $amount): array
    {
        return $this->line(self::ACCOUNT_CODE, $amount, null, null);
    }

    private function line(string $accountCode, float $amount, ?string $partyType = null, mixed $partyId = null): array
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
