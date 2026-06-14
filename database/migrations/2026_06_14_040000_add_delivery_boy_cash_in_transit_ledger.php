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
        $this->ensureDeliveryBoyAccount();

        if (!$this->hasLedgerTables()) {
            return;
        }

        $this->backfillDeliverySaleAssignments();
        $this->backfillDeliveryBoyReceived();
    }

    public function down(): void
    {
        // Do not delete journal history on rollback; it is financial data.
        // If you intentionally need to remove generated entries, filter journal_entries
        // by the memo prefixes used in this migration after taking a backup.
    }

    private function ensureDeliveryBoyAccount(): void
    {
        if (!Schema::hasTable('account_types') || !Schema::hasTable('accounts')) {
            return;
        }

        $now = now();

        $assetTypeId = DB::table('account_types')->where('code', 'ASSET')->value('id');
        if (!$assetTypeId) {
            $assetTypeId = DB::table('account_types')->insertGetId([
                'name' => 'Asset',
                'code' => 'ASSET',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $existing = DB::table('accounts')->where('code', DeliveryBoyLedgerService::ACCOUNT_CODE)->first();
        if ($existing) {
            return;
        }

        $payload = [
            'code' => DeliveryBoyLedgerService::ACCOUNT_CODE,
            'name' => 'Delivery Boy Cash in Transit',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('accounts', 'account_type_id')) {
            $payload['account_type_id'] = (int) $assetTypeId;
        }

        if (Schema::hasColumn('accounts', 'is_leaf')) {
            $payload['is_leaf'] = true;
        }

        DB::table('accounts')->insert($payload);
    }

    private function backfillDeliverySaleAssignments(): void
    {
        if (!Schema::hasTable('sales')) {
            return;
        }

        $sales = DB::table('sales')
            ->select(['id', 'invoice_no', 'customer_id', 'delivery_boy_id', 'branch_id', 'total', 'created_by', 'invoice_date', 'created_at'])
            ->whereNotNull('delivery_boy_id')
            ->when(Schema::hasColumn('sales', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->when(Schema::hasColumn('sales', 'status'), fn ($q) => $q->whereNotIn('status', ['cancelled']))
            ->orderBy('id');

        $sales->chunk(300, function ($rows) {
            foreach ($rows as $sale) {
                $amount = round((float) $sale->total, 2);
                if (abs($amount) < 0.005) {
                    continue;
                }

                $memo = 'Backfill delivery boy assignment for sale #'.($sale->invoice_no ?: $sale->id);
                if ($this->journalExists(Sale::class, (int) $sale->id, $memo)) {
                    continue;
                }

                $this->insertJournal(
                    branchId: $sale->branch_id ? (int) $sale->branch_id : null,
                    memo: $memo,
                    referenceType: Sale::class,
                    referenceId: (int) $sale->id,
                    entryDate: $this->dateValue($sale->invoice_date ?? $sale->created_at),
                    createdBy: $sale->created_by ? (int) $sale->created_by : null,
                    lines: [
                        $this->line(DeliveryBoyLedgerService::ACCOUNT_CODE, $amount, User::class, (int) $sale->delivery_boy_id),
                        $this->line(DeliveryBoyLedgerService::ACCOUNT_CODE, -$amount),
                    ]
                );
            }
        });
    }

    private function backfillDeliveryBoyReceived(): void
    {
        if (!Schema::hasTable('delivery_boy_received')) {
            return;
        }

        $query = DB::table('delivery_boy_received')
            ->select(['id', 'user_id', 'branch_id', 'amount', 'created_at'])
            ->orderBy('id');

        $query->chunk(300, function ($rows) {
            foreach ($rows as $received) {
                $amount = round((float) $received->amount, 2);
                if (abs($amount) < 0.005) {
                    continue;
                }

                $memo = 'Backfill delivery boy cash received #'.$received->id;
                if ($this->journalExists(DeliveryBoyReceived::class, (int) $received->id, $memo)) {
                    continue;
                }

                $this->insertJournal(
                    branchId: $received->branch_id ? (int) $received->branch_id : null,
                    memo: $memo,
                    referenceType: DeliveryBoyReceived::class,
                    referenceId: (int) $received->id,
                    entryDate: $this->dateValue($received->created_at),
                    createdBy: null,
                    lines: [
                        $this->line(DeliveryBoyLedgerService::ACCOUNT_CODE, $amount),
                        $this->line(DeliveryBoyLedgerService::ACCOUNT_CODE, -$amount, User::class, (int) $received->user_id),
                    ]
                );
            }
        });
    }

    private function insertJournal(?int $branchId, string $memo, ?string $referenceType, ?int $referenceId, string $entryDate, ?int $createdBy, array $lines): void
    {
        $sumDebit = round(collect($lines)->sum('debit'), 2);
        $sumCredit = round(collect($lines)->sum('credit'), 2);
        if (abs($sumDebit - $sumCredit) >= 0.005) {
            return;
        }

        $now = now();
        $entryId = DB::table('journal_entries')->insertGetId([
            'entry_date' => $entryDate,
            'memo' => $memo,
            'branch_id' => $branchId,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($lines as $line) {
            $accountId = $this->accountIdByCode((string) $line['account_code']);
            if (!$accountId) {
                continue;
            }

            DB::table('journal_postings')->insert([
                'journal_entry_id' => $entryId,
                'account_id' => $accountId,
                'debit' => round((float) ($line['debit'] ?? 0), 2),
                'credit' => round((float) ($line['credit'] ?? 0), 2),
                'party_type' => $line['party_type'] ?? null,
                'party_id' => $line['party_id'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function line(string $accountCode, float $amount, ?string $partyType = null, ?int $partyId = null): array
    {
        return [
            'account_code' => $accountCode,
            'debit' => $amount > 0 ? abs($amount) : 0,
            'credit' => $amount < 0 ? abs($amount) : 0,
            'party_type' => $partyType,
            'party_id' => $partyId,
        ];
    }

    private function journalExists(string $referenceType, int $referenceId, string $memo): bool
    {
        return DB::table('journal_entries')
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('memo', $memo)
            ->exists();
    }

    private function accountIdByCode(string $code): ?int
    {
        $id = DB::table('accounts')->where('code', $code)->value('id');

        return $id ? (int) $id : null;
    }

    private function dateValue(mixed $value): string
    {
        return $value ? substr((string) $value, 0, 10) : now()->toDateString();
    }

    private function hasLedgerTables(): bool
    {
        return Schema::hasTable('journal_entries')
            && Schema::hasTable('journal_postings')
            && Schema::hasColumn('journal_postings', 'party_type')
            && Schema::hasColumn('journal_postings', 'party_id');
    }
};
