<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Support\Facades\Schema;

class BranchPermissionStateService
{
    public function lockBranch(int $branchId): Branch
    {
        return Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
    }

    public function version(?int $branchId): int
    {
        if (!$branchId || !Schema::hasColumn('branches', 'permission_version')) {
            return 1;
        }

        return max(1, (int) (Branch::query()->whereKey($branchId)->value('permission_version') ?? 1));
    }

    public function bump(Branch $branch): int
    {
        if (!Schema::hasColumn('branches', 'permission_version')) {
            return 1;
        }

        $branch->increment('permission_version');
        $branch->refresh();

        return max(1, (int) $branch->permission_version);
    }
}
