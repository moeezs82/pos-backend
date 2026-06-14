<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Branch;
use App\Services\BranchContextService;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request, BranchContextService $branches)
    {
        $query = Branch::query()->orderBy('name');

        if (!$branches->isMasterAdmin($request->user())) {
            $branchId = $branches->effectiveBranchId($request);
            $branchId ? $query->whereKey($branchId) : $query->whereRaw('1 = 0');
        }

        return ApiResponse::success(['branches' => $query->get()], 'Branches retrieved successfully');
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
            'is_active' => 'boolean',
        ]);

        $branch = Branch::create($data);
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

        $branch = Branch::findOrFail($id);
        $branch->update($request->only(['name', 'location', 'phone', 'is_active']));

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
