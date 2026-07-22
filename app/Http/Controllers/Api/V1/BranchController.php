<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Branch;
use App\Models\BranchSubscription;
use App\Models\SubscriptionAudit;
use App\Services\BranchContextService;
use App\Services\BranchAddonService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    public function index(
        Request $request,
        BranchContextService $branches,
        BranchAddonService $addons
    )
    {
        $query = Branch::query()->orderBy('name');

        if (!$branches->isMasterAdmin($request->user())) {
            $branchId = $branches->effectiveBranchId($request);
            $branchId ? $query->whereKey($branchId) : $query->whereRaw('1 = 0');
        }

        $rows = $query->get();
        $addonMaps = $addons->activeMaps($rows->pluck('id')->map(fn ($id) => (int) $id)->all());
        $payload = $rows->map(function (Branch $branch) use ($addonMaps) {
            return array_merge($branch->toArray(), [
                'addons' => $addonMaps[(int) $branch->id] ?? [
                    BranchAddonService::BARCODE_LABELS => false,
                ],
            ]);
        })->values();

        return ApiResponse::success(['branches' => $payload], 'Branches retrieved successfully');
    }

    public function store(Request $request, BranchContextService $branches)
    {
        if (!$branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only master admin can create branches.', 403);
        }

        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'location'  => 'nullable|string',
            'phone'     => 'nullable|string',
            'currency'  => 'sometimes|required|string|max:20',
            'is_active' => 'boolean',
        ]);

        if (array_key_exists('currency', $data)) {
            $data['currency'] = trim($data['currency']);
        }

        $branch = DB::transaction(function () use ($data, $request) {
            $branch = Branch::create($data);

            // Every new branch must have an explicit subscription record so the
            // fail-closed enforcement does not immediately block it.  We create
            // an active / no-expiry row which the SaaS Owner can configure later.
            $sub = BranchSubscription::create([
                'branch_id'  => $branch->id,
                'status'     => 'active',
                'started_at' => now(),
                'managed_by' => $request->user()->id,
            ]);

            SubscriptionAudit::create([
                'branch_id'      => $branch->id,
                'changed_by'     => $request->user()->id,
                'old_status'     => null,
                'new_status'     => 'active',
                'old_expires_at' => null,
                'new_expires_at' => null,
                'action'         => 'create',
                'reason'         => 'Auto-created with new branch.',
                'metadata'       => [
                    'branch_name' => $branch->name,
                    'changed_by'  => $request->user()->name,
                ],
            ]);

            return $branch;
        });

        return ApiResponse::success(['branch' => $branch], 'Branch created successfully', 201);
    }

    public function show(Request $request, BranchContextService $branches, $id)
    {
        $branch = Branch::findOrFail($id);
        if (!$branches->isMasterAdmin($request->user())) {
            $branches->assertCanAccessBranch($request, (int) $branch->id);
        }

        return ApiResponse::success(['branch' => $branch]);
    }

    public function update(Request $request, BranchContextService $branches, $id)
    {
        if (!$branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only master admin can update branches.', 403);
        }

        $data = $request->validate([
            'name'      => 'sometimes|required|string|max:255',
            'location'  => 'sometimes|nullable|string',
            'phone'     => 'sometimes|nullable|string',
            'currency'  => 'sometimes|required|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        if (array_key_exists('currency', $data)) {
            $data['currency'] = trim($data['currency']);
        }

        $branch = Branch::findOrFail($id);
        $branch->update($data);

        return ApiResponse::success(['branch' => $branch], 'Branch updated successfully');
    }

    public function destroy(Request $request, BranchContextService $branches, $id)
    {
        if (!$branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only master admin can delete branches.', 403);
        }

        Branch::findOrFail($id)->delete();
        return ApiResponse::success(null, 'Branch deleted successfully');
    }
}
