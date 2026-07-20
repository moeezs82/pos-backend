<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Product;
use App\Models\ProductStock;
use App\Services\BranchContextService;
use App\Services\ProductBranchService;
use App\Services\Products\ProductImportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request, BranchContextService $branches, ProductBranchService $productBranches)
    {
        $branchId = $branches->effectiveBranchId($request);

        $query = $productBranches->scopeForBranch(Product::query(), $branchId)
            ->with([
                'category',
                'brand',
                'stocks' => fn ($q) => $branchId ? $q->where('branch_id', $branchId) : $q->whereRaw('1 = 0'),
                'stocks.branch',
            ]);

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($request->filled('vendor_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('vendor_id', $request->vendor_id)
                    ->orWhereNull('vendor_id');
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = strtolower($request->get('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['name', 'price', 'created_at', 'updated_at'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }
        $query->orderBy($sortBy, $sortOrder);

        $page = max(1, (int) $request->get('page', 1));
        $perPage = max(1, min(200, (int) $request->get('per_page', 15)));

        $total = (clone $query)->count();
        $products = $query
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return ApiResponse::success([[
            'products' => $products,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
        ]], 'Products retrieved successfully');
    }

    public function store(Request $request, BranchContextService $branches)
    {
        $branchId = $branches->requireBranchId($request);

        $data = $request->validate([
            'sku' => [
                'nullable',
                Rule::unique('products', 'sku')->where(fn ($q) => $q->where('branch_id', $branchId)),
            ],
            'barcode' => [
                'nullable',
                Rule::unique('products', 'barcode')->where(fn ($q) => $q->where('branch_id', $branchId)),
            ],
            'name' => 'required|string',
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'brand_id' => 'nullable|exists:brands,id',
            'price' => 'required|numeric',
            'cost_price' => 'nullable|numeric',
            'wholesale_price' => 'nullable|numeric',
            'stock' => 'nullable|numeric',
            'tax_rate' => 'nullable|numeric',
            'tax_inclusive' => 'boolean',
            'discount' => 'nullable|numeric',
            'is_active' => 'boolean',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $data['branch_id'] = $branchId;

        if (!empty($data['vendor_id'])) {
            $vendor = \App\Models\Vendor::query()->findOrFail((int) $data['vendor_id']);
            if ($vendor->branch_id && (int) $vendor->branch_id !== $branchId) {
                abort(422, 'Selected vendor belongs to a different branch.');
            }
        }

        return DB::transaction(function () use ($data, $request, $branchId) {
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $name = 'p_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $dir = public_path('images/products');
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $file->move($dir, $name);
                $data['image'] = 'images/products/' . $name;
            }

            $product = Product::create($data);

            // Opening stock (decimal-safe) routed through the shared domain
            // service so manual create and CSV/XLSX import produce identical
            // stock movements and DR 1400 / CR 3100 postings.
            $qty = round((float) ($request->stock ?? 0), 3);
            if ($qty > 0) {
                app(\App\Services\StockPostingService::class)->initializeOpeningStock(
                    $product->id,
                    $branchId,
                    $qty,
                    (float) ($data['cost_price'] ?? 0),
                    [
                        'reference' => 'OPENING',
                        'memo' => "Opening stock for {$product->name} (#{$product->id})",
                        'user_id' => auth()->id(),
                    ],
                );
            } else {
                ProductStock::firstOrCreate(
                    ['product_id' => $product->id, 'branch_id' => $branchId],
                    ['quantity' => 0, 'avg_cost' => $request->cost_price ?? 0]
                );
            }

            return ApiResponse::success(
                $product->load(['category', 'brand', 'stocks' => fn ($q) => $q->where('branch_id', $branchId), 'stocks.branch']),
                'Product created successfully',
                201
            );
        });
    }

    public function show(Request $request, BranchContextService $branches, ProductBranchService $productBranches, $id)
    {
        $branchId = $branches->effectiveBranchId($request);
        $data['product'] = $productBranches->scopeForBranch(Product::query(), $branchId)
            ->with([
                'category',
                'brand',
                'stocks' => fn ($q) => $branchId ? $q->where('branch_id', $branchId) : $q->whereRaw('1 = 0'),
                'stocks.branch',
            ])
            ->findOrFail($id);

        return ApiResponse::success($data, 'Product retrieved successfully');
    }

    public function findByBarcode(Request $request, BranchContextService $branches, ProductBranchService $productBranches, $code, $vendor_id = null)
    {
        $branchId = $branches->effectiveBranchId($request);

        $query = $productBranches->scopeForBranch(Product::query(), $branchId)
            ->where('barcode', $code);

        if ($vendor_id) {
            $query->where('vendor_id', $vendor_id);
        }

        $product = $query->first();
        if (!$product) {
            return ApiResponse::error('Product not found', 404);
        }

        $product->load([
            'category',
            'brand',
            'stocks' => fn ($q) => $branchId ? $q->where('branch_id', $branchId) : $q->whereRaw('1 = 0'),
            'stocks.branch',
        ]);

        return ApiResponse::success($product, 'Product retrieved successfully');
    }

    public function update(Request $request, BranchContextService $branches, ProductBranchService $productBranches, $id)
    {
        $branchId = $branches->requireBranchId($request);
        $product = $productBranches->findForBranch((int) $id, $branchId);

        $data = $request->validate([
            'sku' => [
                'sometimes',
                'required',
                Rule::unique('products', 'sku')->where(fn ($q) => $q->where('branch_id', $branchId))->ignore($product->id),
            ],
            'barcode' => [
                'nullable',
                Rule::unique('products', 'barcode')->where(fn ($q) => $q->where('branch_id', $branchId))->ignore($product->id),
            ],
            'name' => 'sometimes|required|string',
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'brand_id' => 'nullable|exists:brands,id',
            'price' => 'numeric',
            'cost_price' => 'nullable|numeric',
            'wholesale_price' => 'nullable|numeric',
            'tax_rate' => 'nullable|numeric',
            'tax_inclusive' => 'boolean',
            'discount' => 'nullable|numeric',
            'is_active' => 'boolean',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        if (!empty($data['vendor_id'])) {
            $vendor = \App\Models\Vendor::query()->findOrFail((int) $data['vendor_id']);
            if ($vendor->branch_id && (int) $vendor->branch_id !== $branchId) {
                abort(422, 'Selected vendor belongs to a different branch.');
            }
        }

        if ($request->hasFile('image')) {
            if (!empty($product->image) && file_exists(public_path($product->image))) {
                @unlink(public_path($product->image));
            }

            $file = $request->file('image');
            $name = 'p_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $dir = public_path('images/products');
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $file->move($dir, $name);
            $data['image'] = 'images/products/' . $name;
        }

        if ($product->cost_price != ($data['cost_price'] ?? $product->cost_price)) {
            DB::table('product_stocks')
                ->where('product_id', $product->id)
                ->where('branch_id', $branchId)
                ->update(['avg_cost' => $data['cost_price']]);
        }

        $product->update($data);

        $data['product'] = $product->fresh()->load([
            'category',
            'brand',
            'stocks' => fn ($q) => $q->where('branch_id', $branchId),
            'stocks.branch',
        ]);

        return ApiResponse::success($data, 'Product updated successfully');
    }

    public function destroy(Request $request, BranchContextService $branches, ProductBranchService $productBranches, $id)
    {
        $branchId = $branches->requireBranchId($request);
        $product = $productBranches->findForBranch((int) $id, $branchId);

        return DB::transaction(function () use ($product, $branchId) {
            // Lock the branch stock row while validating deletion so an unused
            // product cannot be removed with a non-zero on-hand quantity.
            $stockQuantity = (float) DB::table('product_stocks')
                ->where('product_id', $product->id)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->value('quantity');

            if (abs($stockQuantity) > 0.0005) {
                return ApiResponse::error(
                    'This product cannot be deleted because it has non-zero stock. '
                    . 'Sell, return, transfer, or adjust the stock to zero, then deactivate the product if it has history.',
                    422,
                    [
                        'stock' => [
                            'Current stock quantity: ' . round($stockQuantity, 3),
                        ],
                    ]
                );
            }

            // Financial and inventory history must remain linked to a visible
            // product record. Products with history should be deactivated, not
            // deleted, even after their current stock reaches zero.
            $historyTables = [
                'stock_movements',
                'sale_items',
                'sale_return_items',
                'purchase_items',
                'purchase_claim_items',
            ];

            foreach ($historyTables as $table) {
                if (DB::table($table)->where('product_id', $product->id)->exists()) {
                    return ApiResponse::error(
                        'This product cannot be deleted because inventory, sale, purchase, or return history exists. '
                        . 'Set the product as inactive to preserve accounting and reporting history.',
                        422,
                        [
                            'product' => [
                                'Historical products must be deactivated instead of deleted.',
                            ],
                        ]
                    );
                }
            }

            $product->delete();

            return ApiResponse::success(null, 'Product deleted successfully.');
        });
    }

    /**
     * Stream the current branch's product catalog as CSV or XLSX.
     * Respects the same filters as index() (search, vendor/category/brand,
     * is_active) so a filtered list view can be exported as-is.
     */
    public function export(
        Request $request,
        BranchContextService $branches,
        ProductBranchService $productBranches,
        ProductImportExportService $importExport
    ) {
        $branchId = $branches->effectiveBranchId($request);
        $format = strtolower((string) $request->get('format', 'xlsx'));

        if (!in_array($format, ['csv', 'xlsx'], true)) {
            return ApiResponse::error('Unsupported export format. Use csv or xlsx.', 422);
        }

        $query = $productBranches->scopeForBranch(Product::query(), $branchId)
            ->with(['category', 'brand', 'vendor']);

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $products = $query->orderBy('name')->get();
        $rows = $importExport->rowsForExport($products);
        $timestamp = now()->format('Ymd-His');

        if ($format === 'csv') {
            return response($importExport->exportToCsv($rows), 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"products-export-{$timestamp}.csv\"",
            ]);
        }

        $path = $importExport->exportToXlsx($rows, 'Products Export');

        return response()->download($path, "products-export-{$timestamp}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Downloadable CSV/XLSX template with the exact column headers the
     * import endpoint expects, pre-filled with one example row.
     */
    public function importTemplate(Request $request, ProductImportExportService $importExport)
    {
        $format = strtolower((string) $request->get('format', 'xlsx'));

        if (!in_array($format, ['csv', 'xlsx'], true)) {
            return ApiResponse::error('Unsupported template format. Use csv or xlsx.', 422);
        }

        $rows = $importExport->templateRows();

        if ($format === 'csv') {
            return response($importExport->exportToCsv($rows), 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="product-import-template.csv"',
            ]);
        }

        $path = $importExport->exportToXlsx($rows, 'Product Import Template');

        return response()->download($path, 'product-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Bulk-import products from a CSV/XLSX upload. Category/brand/vendor
     * are matched by name. Each row is validated and applied independently
     * so one bad row doesn't fail the whole batch — the response contains
     * a per-row success/error report.
     */
    public function import(Request $request, BranchContextService $branches, ProductImportExportService $importExport)
    {
        $branchId = $branches->requireBranchId($request);

        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx|max:10240',
        ]);

        $rows = $importExport->parseUpload($request->file('file'));

        if (empty($rows)) {
            return ApiResponse::error('The uploaded file has no data rows.', 422);
        }

        $report = $importExport->importRows($rows, $branchId);

        return ApiResponse::success($report, 'Import completed');
    }
}
