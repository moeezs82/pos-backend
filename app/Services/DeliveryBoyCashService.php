<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeliveryBoyCashService
{
    /**
     * Build delivery cash summaries for many users in only two aggregate queries.
     *
     * Balance meaning:
     * assigned delivery order total - cash already received from delivery boy.
     * A positive balance means the delivery boy still owes money to the shop.
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

        $ordersQuery = DB::table('sales')
            ->when($this->hasColumn('sales', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->whereNotIn('status', ['cancelled'])
            ->whereIn('delivery_boy_id', $ids->all());

        $this->applyCommonFilters($ordersQuery, 'created_at', $filters);

        $branchId = (int) ($filters['branch_id'] ?? 0);
        if ($branchId > 0) {
            $ordersQuery->where('branch_id', $branchId);
        }

        $ordersByUser = (clone $ordersQuery)
            ->selectRaw('delivery_boy_id AS user_id')
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw('COALESCE(SUM(total), 0) AS orders_total')
            ->selectRaw('MAX(created_at) AS last_order_at')
            ->groupBy('delivery_boy_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->user_id);

        $receivedQuery = DB::table('delivery_boy_received')
            ->whereIn('user_id', $ids->all());

        if ($branchId > 0 && $this->hasColumn('delivery_boy_received', 'branch_id')) {
            $receivedQuery->where('branch_id', $branchId);
        }

        $this->applyCommonFilters($receivedQuery, 'created_at', $filters);

        $receivedByUser = (clone $receivedQuery)
            ->selectRaw('user_id')
            ->selectRaw('COUNT(*) AS received_count')
            ->selectRaw('COALESCE(SUM(amount), 0) AS received_total')
            ->selectRaw('MAX(created_at) AS last_received_at')
            ->groupBy('user_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->user_id);

        return $ids->mapWithKeys(function (int $userId) use ($ordersByUser, $receivedByUser, $branchId) {
            $orders = $ordersByUser->get($userId);
            $received = $receivedByUser->get($userId);

            $ordersTotal = (float) ($orders->orders_total ?? 0);
            $receivedTotal = (float) ($received->received_total ?? 0);

            return [$userId => [
                'orders_count' => (int) ($orders->orders_count ?? 0),
                'orders_total' => round($ordersTotal, 2),
                'received_count' => (int) ($received->received_count ?? 0),
                'received_total' => round($receivedTotal, 2),
                'balance' => round($ordersTotal - $receivedTotal, 2),
                'last_order_at' => $orders->last_order_at ?? null,
                'last_received_at' => $received->last_received_at ?? null,
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

    private function applyCommonFilters($query, string $dateColumn, array $filters): void
    {
        if (!empty($filters['from'])) {
            $query->whereDate($dateColumn, '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate($dateColumn, '<=', $filters['to']);
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
