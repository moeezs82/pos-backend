<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ProductBranchService
{
    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        if (!$branchId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('branch_id', $branchId);
    }

    public function queryForBranch(?int $branchId): Builder
    {
        return $this->scopeForBranch(Product::query(), $branchId);
    }

    public function findForBranch(int $productId, ?int $branchId): Product
    {
        return $this->queryForBranch($branchId)->whereKey($productId)->firstOrFail();
    }

    public function assertProductsBelongToBranch(iterable $productIds, int $branchId): void
    {
        $ids = collect($productIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $found = Product::query()
            ->whereIn('id', $ids)
            ->where('branch_id', $branchId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missing = $ids->diff($found)->values()->all();

        if (!empty($missing)) {
            throw ValidationException::withMessages([
                'product_id' => ['One or more selected products do not belong to the active branch: ' . implode(', ', $missing)],
            ]);
        }
    }
}
