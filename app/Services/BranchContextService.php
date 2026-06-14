<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BranchContextService
{
    public function isMasterAdmin(?Authenticatable $user = null): bool
    {
        if (!$user instanceof User) {
            $user = auth()->user();
        }

        return $user instanceof User && $user->isMasterAdmin();
    }

    public function requestedBranchId(Request $request): ?int
    {
        $raw = $request->input('branch_id');

        if ($raw === null || $raw === '') {
            $raw = $request->header('X-Branch-Id');
        }

        if ($raw === null || $raw === '' || strtolower((string) $raw) === 'all') {
            return null;
        }

        return (int) $raw > 0 ? (int) $raw : null;
    }

    /**
     * The effective branch is always the logged-in user's active branch.
     * Master admin changes this only through the dedicated switch-branch endpoint.
     */
    public function effectiveBranchId(Request $request): ?int
    {
        $user = $request->user();

        return $user?->branch_id ? (int) $user->branch_id : null;
    }

    public function requireBranchId(Request $request): int
    {
        $branchId = $this->effectiveBranchId($request);

        if (!$branchId) {
            throw ValidationException::withMessages([
                'branch_id' => ['Please select a branch. Branch is required for this operation.'],
            ]);
        }

        if (!Branch::query()->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages([
                'branch_id' => ['Selected branch does not exist.'],
            ]);
        }

        $this->assertCanAccessBranch($request, $branchId);

        return $branchId;
    }

    public function assertCanAccessBranch(Request $request, ?int $branchId): void
    {
        if (!$branchId) {
            return;
        }

        $user = $request->user();
        $userBranchId = $user?->branch_id ? (int) $user->branch_id : null;

        // Master admin can change active branch only through switchBranch().
        // Everywhere else, selected user.branch_id is the working branch.
        if ($this->isMasterAdmin($user)) {
            if ($userBranchId && $userBranchId === (int) $branchId) {
                return;
            }

            throw ValidationException::withMessages([
                'branch_id' => ['Please switch to this branch first.'],
            ]);
        }

        if (!$userBranchId || $userBranchId !== (int) $branchId) {
            throw ValidationException::withMessages([
                'branch_id' => ['You do not have access to this branch.'],
            ]);
        }
    }

    public function applyToQuery(
        EloquentBuilder|QueryBuilder $query,
        Request $request,
        string $column = 'branch_id'
    ): EloquentBuilder|QueryBuilder {
        $branchId = $this->effectiveBranchId($request);

        if (!$branchId) {
            $query->whereRaw('1 = 0');
            return $query;
        }

        $query->where($column, $branchId);

        return $query;
    }

    public function mergeEffectiveBranchIntoRequest(Request $request): void
    {
        $branchId = $this->effectiveBranchId($request);

        if ($branchId) {
            $request->merge(['branch_id' => $branchId]);
        }

        $request->attributes->set('effective_branch_id', $branchId);
        $request->attributes->set('is_master_admin', $this->isMasterAdmin($request->user()));
    }

    public function branchPayload(?int $branchId): ?array
    {
        if (!$branchId) {
            return null;
        }

        $branch = Branch::query()->select(['id', 'name', 'location', 'phone', 'is_active'])->find($branchId);

        return $branch ? $branch->toArray() : null;
    }

    public function supportsColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
