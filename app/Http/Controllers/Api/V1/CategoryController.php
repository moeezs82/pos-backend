<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {
        $data['categories'] = Category::orderBy('name')->get(['id', 'name']);
        return ApiResponse::success($data, 'categories retrieved successfully');
    }

    // Create category
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255'
        ]);

        $category = Category::create($data);
        $data['category'] = $category;
        return ApiResponse::success($data, 'category created successfully', 201);
    }

    // Show single category
    public function show($id)
    {
        $data['category'] = Category::findOrFail($id);
        return ApiResponse::success($data);
    }

    // Update category
    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);
        $category->update($request->only(['name']));
        $data['category'] = $category;
        return ApiResponse::success($data, 'category updated successfully');
    }

    // Delete category
    public function destroy($id)
    {
        Category::findOrFail($id)->delete();
        return ApiResponse::success(null, 'category deleted successfully');
    }
}
