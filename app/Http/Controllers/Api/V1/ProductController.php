<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // 📂 Filter by vendor
        if ($request->filled('vendor_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('vendor_id', $request->vendor_id)
                    ->orWhereNull('vendor_id');
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
            'sku'            => 'nullable|unique:products',
            'barcode'        => 'nullable|unique:products',
            'name'           => 'required|string',
            'description'    => 'nullable|string',
            'category_id'    => 'nullable|exists:categories,id',
            'vendor_id'      => 'nullable|exists:vendors,id',
            'brand_id'       => 'nullable|exists:brands,id',
            'price'          => 'required|numeric',
            'cost_price'     => 'nullable|numeric',
            'wholesale_price' => 'nullable|numeric',
            'stock' => 'nullable|numeric',
            'tax_rate'       => 'nullable|numeric',
            'tax_inclusive'  => 'boolean',
            'discount'       => 'nullable|numeric',
            'is_active'      => 'boolean',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $product = Product::create($data);
            if ($request->has('stock') && $request->stock > 0) {
                $unitCost = (float) ($data['cost_price'] ?? 0); // cost given while creating product
                $asOf     = now()->toDateString();

                $branchId = null;
                $qty      = (int) ($request->stock ?? 0);
                if ($qty > 0) {
                    // 1) Update stocks with moving-average (opening)
                    app(\App\Services\InventoryValuationWriteService::class)->receivePurchase(
                        productId: $product->id,
                        branchId: $branchId,
                        receiveQty: $qty,
                        unitPrice: $unitCost,
                        ref: 'OPENING'
                    );
                }


                // 2) Accumulate branch value for GL posting
                $value = round($qty * $unitCost, 2);

                // 3) Post GL JE per branch: DR Inventory (1400) / CR Opening Equity (3100)
                if ($value > 0) {
                    app(\App\Services\AccountingService::class)->post(
                        branchId: $branchId,
                        memo: "Opening stock for {$product->name} (#{$product->id})",
                        reference: $product, // uses morph (reference_type/id)
                        lines: [
                            ['account_code' => '1400', 'debit' => $value, 'credit' => 0], // Inventory
                            ['account_code' => '3100', 'debit' => 0,      'credit' => $value], // Opening/Retained Earnings
                        ],
                        entryDate: $asOf,
                        userId: auth()->id()
                    );
                }
            }

            // initialize stock in all branches
            // if ($request->has('branch_stocks')) {
            //     $unitCost = (float) ($data['cost_price'] ?? 0); // cost given while creating product
            //     $asOf     = now()->toDateString();

            //     // aggregate value per-branch to post one JE per branch
            //     $valueByBranch = [];

            //     foreach ($request->branch_stocks as $stock) {
            //         $branchId = (int) $stock['branch_id'];
            //         $qty      = (int) ($stock['quantity'] ?? 0);
            //         if ($qty <= 0) continue;

            //         // 1) Update stocks with moving-average (opening)
            //         app(\App\Services\InventoryValuationWriteService::class)->receivePurchase(
            //             productId: $product->id,
            //             branchId: $branchId,
            //             receiveQty: $qty,
            //             unitPrice: $unitCost,
            //             ref: 'OPENING'
            //         );

            //         // (Optional) ensure a stock row exists even if service handles it
            //         // ProductStock::firstOrCreate(['product_id'=>$product->id,'branch_id'=>$branchId]);

            //         // 2) Accumulate branch value for GL posting
            //         $valueByBranch[$branchId] = ($valueByBranch[$branchId] ?? 0) + round($qty * $unitCost, 2);
            //     }

            //     // 3) Post GL JE per branch: DR Inventory (1400) / CR Opening Equity (3100)
            //     foreach ($valueByBranch as $branchId => $value) {
            //         if ($value <= 0) continue;

            //         app(\App\Services\AccountingService::class)->post(
            //             branchId: $branchId,
            //             memo: "Opening stock for {$product->name} (#{$product->id})",
            //             reference: $product, // uses morph (reference_type/id)
            //             lines: [
            //                 ['account_code' => '1400', 'debit' => $value, 'credit' => 0], // Inventory
            //                 ['account_code' => '3100', 'debit' => 0,      'credit' => $value], // Opening/Retained Earnings
            //             ],
            //             entryDate: $asOf,
            //             userId: auth()->id()
            //         );
            //     }
            // }

            return ApiResponse::success($product->load('category', 'brand', 'stocks'), 'Product created successfully', 201);
        });
    }

    // Show product
    public function show($id)
    {
        $data['product'] = Product::with(['category', 'brand', 'stocks.branch'])->findOrFail($id);
        return ApiResponse::success($data, 'Product retrived successfully');
    }
    public function findByBarcode($code, $vendor_id = null)
    {
        if ($vendor_id) {
            $product = Product::where('barcode', $code)->where('vendor_id', $vendor_id)->first();
        } else {
            $product = Product::where('barcode', $code)->first();
        }
        if (!$product) {
            return ApiResponse::error("Product not found", 404);
        }
        return ApiResponse::success($product, 'Product retrived successfully');
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
