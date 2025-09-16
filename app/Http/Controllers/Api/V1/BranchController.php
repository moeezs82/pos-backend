<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    // List branches
    public function index()
    {
        $data['branches'] = Branch::all();
        return ApiResponse::success($data, 'Branches retrieved successfully');
    }

    // Create branch
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'location' => 'nullable|string',
            'phone'    => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $branch = Branch::create($data);
        $data['branch'] = $branch;
        return ApiResponse::success($data, 'Branch created successfully', 201);
    }

    // Show single branch
    public function show($id)
    {
        $data['branch'] = Branch::findOrFail($id);
        return ApiResponse::success($data);
    }

    // Update branch
    public function update(Request $request, $id)
    {
        $branch = Branch::findOrFail($id);
        $branch->update($request->only(['name', 'location', 'phone', 'is_active']));
        $data['branch'] = $branch;
        return ApiResponse::success($data, 'Branch updated successfully');
    }

    // Delete branch
    public function destroy($id)
    {
        Branch::findOrFail($id)->delete();
        return ApiResponse::success(null, 'Branch deleted successfully');
    }
}
