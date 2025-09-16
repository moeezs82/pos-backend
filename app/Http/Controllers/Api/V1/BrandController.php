<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Brand;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index()
    {
        $data['brands'] = Brand::all();
        return ApiResponse::success($data, 'brands retrieved successfully');
    }

    // Create brand
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255'
        ]);

        $brand = Brand::create($data);
        $data['brand'] = $brand;
        return ApiResponse::success($data, 'brand created successfully', 201);
    }

    // Show single brand
    public function show($id)
    {
        $data['brand'] = Brand::findOrFail($id);
        return ApiResponse::success($data);
    }

    // Update brand
    public function update(Request $request, $id)
    {
        $brand = Brand::findOrFail($id);
        $brand->update($request->only(['name']));
        $data['brand'] = $brand;
        return ApiResponse::success($data, 'brand updated successfully');
    }

    // Delete brand
    public function destroy($id)
    {
        Brand::findOrFail($id)->delete();
        return ApiResponse::success(null, 'brand deleted successfully');
    }
}
