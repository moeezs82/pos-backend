<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeliveryBoyCashService
{
    /**
     * Build delivery cash summaries from the accounting ledger.
     *
     * Debit  on account 1210 = delivery boy owes / cash in transit.
     * Credit on account 1210 = delivery boy/customer cash received.
     * Balance = debit - credit.
     *
     * This survives financial-year close because opening journal postings keep
     * party_type=App\Models\User and party_id=delivery_boy_id.
     *
     * Supported filters: from, to, branch_id.
     */
    public function summariesForUsers(iterable $userIds, array $filters = []): array
    {
        $ids = collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $accountId = $this->deliveryBoyAccountId();
        if (!$accountId || !$this->hasLedgerTables()) {
            return $this->emptySummaries($ids->all(), (int) ($filters['branch_id'] ?? 0));
        }

        $query = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->where('jp.account_id', $accountId)
            ->where('jp.party_type', User::class)
            ->whereIn('jp.party_id', $ids->all());

        $branchId = (int) ($filters['branch_id'] ?? 0);
        if ($branchId > 0) {
            $query->where('je.branch_id', $branchId);
        }

        $this->applyCommonFilters($query, 'je.entry_date', $filters);

        $rows = (clone $query)
            ->selectRaw('jp.party_id AS user_id')
            ->selectRaw('SUM(CASE WHEN COALESCE(jp.debit, 0) > 0 THEN 1 ELSE 0 END) AS orders_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(jp.credit, 0) > 0 THEN 1 ELSE 0 END) AS received_count')
            ->selectRaw('COALESCE(SUM(jp.debit), 0) AS orders_total')
            ->selectRaw('COALESCE(SUM(jp.credit), 0) AS received_total')
            ->selectRaw('COALESCE(SUM(jp.debit - jp.credit), 0) AS balance')
            ->selectRaw('MAX(CASE WHEN COALESCE(jp.debit, 0) > 0 THEN je.entry_date ELSE NULL END) AS last_order_at')
            ->selectRaw('MAX(CASE WHEN COALESCE(jp.credit, 0) > 0 THEN je.entry_date ELSE NULL END) AS last_received_at')
            ->groupBy('jp.party_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->user_id);

        return $ids->mapWithKeys(function (int $userId) use ($rows, $branchId) {
            $row = $rows->get($userId);

            return [$userId => [
                'orders_count' => (int) ($row->orders_count ?? 0),
                'orders_total' => round((float) ($row->orders_total ?? 0), 2),
                'received_count' => (int) ($row->received_count ?? 0),
                'received_total' => round((float) ($row->received_total ?? 0), 2),
                'balance' => round((float) ($row->balance ?? 0), 2),
                'last_order_at' => $row->last_order_at ?? null,
                'last_received_at' => $row->last_received_at ?? null,
                'branch_id' => $branchId > 0 ? $branchId : null,
            ]];
        })->all();
    }

    public function summaryForUser(User|int $user, array $filters = []): array
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return $this->summariesForUsers([$userId], $filters)[$userId] ?? [
            'orders_count' => 0,
            'orders_total' => 0.0,
            'received_count' => 0,
            'received_total' => 0.0,
            'balance' => 0.0,
            'last_order_at' => null,
            'last_received_at' => null,
            'branch_id' => null,
        ];
    }

    public function filtersFromRequest($request): array
    {
        return [
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'branch_id' => $request->integer('branch_id'),
        ];
    }

    public function deliveryBoyAccountId(): ?int
    {
        if (!Schema::hasTable('accounts')) {
            return null;
        }

        $id = DB::table('accounts')->where('code', DeliveryBoyLedgerService::ACCOUNT_CODE)->value('id');

        return $id ? (int) $id : null;
    }

    private function applyCommonFilters($query, string $dateColumn, array $filters): void
    {
        if (!empty($filters['from'])) {
            $query->whereDate($dateColumn, '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate($dateColumn, '<=', $filters['to']);
        }
    }

    private function hasLedgerTables(): bool
    {
        return Schema::hasTable('journal_entries')
            && Schema::hasTable('journal_postings')
            && Schema::hasColumn('journal_postings', 'party_type')
            && Schema::hasColumn('journal_postings', 'party_id');
    }

    private function emptySummaries(array $ids, int $branchId): array
    {
        return collect($ids)->mapWithKeys(fn (int $userId) => [$userId => [
            'orders_count' => 0,
            'orders_total' => 0.0,
            'received_count' => 0,
            'received_total' => 0.0,
            'balance' => 0.0,
            'last_order_at' => null,
            'last_received_at' => null,
            'branch_id' => $branchId > 0 ? $branchId : null,
        ]])->all();
    }
}
