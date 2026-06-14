<?php

use App\Models\Customer;
use App\Models\DeliveryBoyReceived;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\User;
use App\Services\DeliveryBoyLedgerService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!$this->hasLedgerTables()) {
            return;
        }

        $deliveryAccountId = $this->accountIdByCode(DeliveryBoyLedgerService::ACCOUNT_CODE);
        if (!$deliveryAccountId) {
            return;
        }

        $this->removeLegacyDeliveryReceiptBackfills();
        $this->removeCustomerArEffectFromDeliverySaleAssignments($deliveryAccountId);
        $this->restoreCustomerFlowForDeliverySaleReceipts($deliveryAccountId);
        $this->removeCashEffectFromDeliveryBoyReceived($deliveryAccountId);
    }

    public function down(): void
    {
        // This migration corrects financial posting behavior. Do not reverse it
        // automatically because that would reintroduce customer AR side effects.
    }

    /**
     * Older delivery ledger patch inserted extra entries for customer receipts
     * allocated to delivery sales. Customer receipts must stay customer flow, so
     * those extra backfill entries are removed.
     */
    private function removeLegacyDeliveryReceiptBackfills(): void
    {
        $entryIds = DB::table('journal_entries')
            ->where('memo', 'like', 'Backfill delivery boy customer receipt #%')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (!$entryIds) {
            return;
        }

        DB::table('journal_postings')->whereIn('journal_entry_id', $entryIds)->delete();
        DB::table('journal_entries')->whereIn('id', $entryIds)->delete();
    }

    /**
     * Delivery sale assignment should add delivery-boy custody balance only.
     * It must not credit/debit customer AR. Convert any old AR counter-lines to
     * a no-party counter-line on the delivery custody account.
     */
    private function removeCustomerArEffectFromDeliverySaleAssignments(int $deliveryAccountId): void
    {
        $customerArAccountId = $this->accountIdByCode('1200');
        if (!$customerArAccountId) {
            return;
        }

        $entryIds = DB::table('journal_entries as je')
            ->join('journal_postings as jp', 'jp.journal_entry_id', '=', 'je.id')
            ->where('je.reference_type', Sale::class)
            ->where('je.memo', 'like', '%delivery boy%')
            ->where('jp.account_id', $customerArAccountId)
            ->pluck('je.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        foreach ($entryIds as $entryId) {
            DB::table('journal_postings')
                ->where('journal_entry_id', $entryId)
                ->where('account_id', $customerArAccountId)
                ->update([
                    'account_id' => $deliveryAccountId,
                    'party_type' => null,
                    'party_id' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Older runtime code posted customer receipts for delivery sales against
     * delivery-boy custody. That is now wrong: customer receipt should always
     * credit customer AR, regardless of delivery boy assignment.
     */
    private function restoreCustomerFlowForDeliverySaleReceipts(int $deliveryAccountId): void
    {
        $customerArAccountId = $this->accountIdByCode('1200');
        if (!$customerArAccountId || !Schema::hasTable('receipts')) {
            return;
        }

        $entries = DB::table('journal_entries as je')
            ->join('receipts as r', 'r.id', '=', 'je.reference_id')
            ->where('je.reference_type', Receipt::class)
            ->where('je.memo', 'like', 'Customer receipt #% for delivery sale #%')
            ->select('je.id', 'r.customer_id')
            ->get();

        foreach ($entries as $entry) {
            DB::table('journal_postings')
                ->where('journal_entry_id', (int) $entry->id)
                ->where('account_id', $deliveryAccountId)
                ->where('party_type', User::class)
                ->update([
                    'account_id' => $customerArAccountId,
                    'party_type' => Customer::class,
                    'party_id' => $entry->customer_id ? (int) $entry->customer_id : null,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * DeliveryBoyReceived is used to reduce the delivery-boy custody balance.
     * It should not post a cash/bank entry here because customer/cash accounting
     * remains separate from the operational delivery-boy custody ledger.
     */
    private function removeCashEffectFromDeliveryBoyReceived(int $deliveryAccountId): void
    {
        $entryIds = DB::table('journal_entries')
            ->where('reference_type', DeliveryBoyReceived::class)
            ->where('memo', 'like', '%delivery boy cash received%')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($entryIds as $entryId) {
            DB::table('journal_postings')
                ->where('journal_entry_id', $entryId)
                ->where('account_id', '<>', $deliveryAccountId)
                ->update([
                    'account_id' => $deliveryAccountId,
                    'party_type' => null,
                    'party_id' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    private function accountIdByCode(string $code): ?int
    {
        if (!Schema::hasTable('accounts')) {
            return null;
        }

        $id = DB::table('accounts')->where('code', $code)->value('id');

        return $id ? (int) $id : null;
    }

    private function hasLedgerTables(): bool
    {
        return Schema::hasTable('journal_entries')
            && Schema::hasTable('journal_postings')
            && Schema::hasColumn('journal_postings', 'party_type')
            && Schema::hasColumn('journal_postings', 'party_id');
    }
};
