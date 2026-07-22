<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Branch;
use App\Services\BranchAddonService;
use App\Services\BranchContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchAddonController extends Controller
{
    public function __construct(
        private BranchAddonService $addons,
        private BranchContextService $branches,
    ) {}

    public function current(Request $request)
    {
        $branchId = $this->branches->requireBranchId($request);

        return ApiResponse::success([
            'branch_id' => $branchId,
            'addons' => $this->addons->catalogForBranch($branchId),
        ]);
    }

    public function show(Request $request, Branch $branch)
    {
        $this->requireOwner($request);

        return ApiResponse::success([
            'branch' => $branch->only(['id', 'name']),
            'addons' => $this->addons->catalogForBranch((int) $branch->id),
        ]);
    }

    public function update(Request $request, Branch $branch)
    {
        $this->requireOwner($request);
        $data = $request->validate([
            'addons' => ['required', 'array'],
            'addons.barcode_labels' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $catalog = $this->addons->updateMany(
            (int) $branch->id,
            $data['addons'],
            (int) $request->user()->id,
            $data['reason'] ?? null,
            ['branch_name' => $branch->name]
        );

        return ApiResponse::success(['addons' => $catalog], 'Branch add-ons updated successfully.');
    }

    public function audit(Request $request, Branch $branch)
    {
        $this->requireOwner($request);
        $rows = DB::table('branch_addon_audits as baa')
            ->leftJoin('users as u', 'u.id', '=', 'baa.changed_by')
            ->where('baa.branch_id', $branch->id)
            ->select('baa.*', 'u.name as changed_by_name', 'u.email as changed_by_email')
            ->orderByDesc('baa.id')
            ->paginate(20);

        return ApiResponse::success($rows);
    }

    private function requireOwner(Request $request): void
    {
        if (!$this->branches->isMasterAdmin($request->user())) {
            abort(403, 'Only the platform owner can manage branch add-ons.');
        }
    }
}
