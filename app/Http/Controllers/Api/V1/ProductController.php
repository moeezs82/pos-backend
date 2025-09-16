<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with(['category', 'brand', 'stocks.branch']);

        // 🔎 Search by name, SKU, barcode
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                    ->orWhere('sku', 'like', "%$search%")
                    ->orWhere('barcode', 'like', "%$search%");
            });
        }

        // 📂 Filter by category
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // 🏷️ Filter by brand
        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        // ✅ Active/inactive filter
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        // 📊 Sorting (default: latest created)
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $allowedSorts = ['name', 'price', 'created_at', 'updated_at'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'created_at';
        }
        $query->orderBy($sortBy, $sortOrder);

        // 📄 Custom pagination with skip & take
        $page = (int) $request->get('page', 1);   // default: page 1
        $perPage = (int) $request->get('per_page', 15); // default: 15

        $total = $query->count();

        $skip = ($page - 1) * $perPage;
        $products = $query
            ->skip($skip)
            ->take($perPage)
            ->get();

        $data[] = [
            'products' => $products,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => ceil($total / $perPage),
        ];

        return ApiResponse::success($data, 'Products retrived successfully');
    }

    // Create product
    public function store(Request $request)
    {
        $data = $request->validate([
            'sku'            => 'required|unique:products',
            'barcode'        => 'nullable|unique:products',
            'name'           => 'required|string',
            'description'    => 'nullable|string',
            'category_id'    => 'nullable|exists:categories,id',
            'brand_id'       => 'nullable|exists:brands,id',
            'price'          => 'required|numeric',
            'cost_price'     => 'nullable|numeric',
            'wholesale_price' => 'nullable|numeric',
            'tax_rate'       => 'nullable|numeric',
            'tax_inclusive'  => 'boolean',
            'discount'       => 'nullable|numeric',
            'is_active'      => 'boolean',
        ]);

        $product = Product::create($data);

        // initialize stock in all branches
        if ($request->has('branch_stocks')) {
            foreach ($request->branch_stocks as $stock) {
                ProductStock::create([
                    'product_id' => $product->id,
                    'branch_id'  => $stock['branch_id'],
                    'quantity'   => $stock['quantity'] ?? 0,
                ]);
            }
        }

        $data['product'] = $product->load('category', 'brand', 'stocks');

        return ApiResponse::success($data, 'Product created successfully', 201);
    }

    // Show product
    public function show($id)
    {
        $data['product'] = Product::with(['category', 'brand', 'stocks.branch'])->findOrFail($id);
        return ApiResponse::success($data, 'Product retrived successfully');
    }

    // Update product
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $data = $request->validate([
            'sku'            => 'sometimes|required|unique:products,sku,' . $id,
            'barcode'        => 'nullable|unique:products,barcode,' . $id,
            'name'           => 'sometimes|required|string',
            'description'    => 'nullable|string',
            'category_id'    => 'nullable|exists:categories,id',
            'brand_id'       => 'nullable|exists:brands,id',
            'price'          => 'numeric',
            'cost_price'     => 'nullable|numeric',
            'wholesale_price' => 'nullable|numeric',
            'tax_rate'       => 'nullable|numeric',
            'tax_inclusive'  => 'boolean',
            'discount'       => 'nullable|numeric',
            'is_active'      => 'boolean',
        ]);

        $product->update($data);

        $data['product'] = $product->load('category', 'brand', 'stocks');

        return ApiResponse::success($data, 'Product updated successfully');
    }

    // Delete product
    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return ApiResponse::success(null);
    }
}
