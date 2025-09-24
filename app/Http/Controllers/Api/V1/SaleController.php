<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaleController extends Controller
{
    // List all sales
    public function index(Request $request)
    {
        $query = Sale::with(['customer', 'branch'])
            ->withSum('payments as paid_amount', 'amount');

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_no', 'like', "%$search%")
                    ->orWhereHas('customer', function ($c) use ($search) {
                        $c->where('first_name', 'like', "%$search%")
                            ->orWhere('last_name', 'like', "%$search%")
                            ->orWhere('email', 'like', "%$search%")
                            ->orWhere('phone', 'like', "%$search%");
                    });
            });
        }

        if ($request->sort_by == 'total') {
            $query->orderBy('total', 'desc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $sales = $query->paginate(15);

        return ApiResponse::success($sales);
    }

    // Get single sale with details
    public function show($id)
    {
        $sale = Sale::with('customer', 'branch', 'items.product', 'payments')->findOrFail($id);

        return ApiResponse::success($sale);
    }


    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'branch_id'   => 'required|exists:branches,id',
            'items'       => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.price'      => 'required|numeric|min:0',
            'discount'    => 'numeric|min:0',
            'tax'         => 'numeric|min:0',
            'payments'    => 'array'
        ]);

        $branchId = $data['branch_id'];

        // ✅ Validate stock with helper
        // $validation = $this->validateStock($branchId, $data['items']);
        // if (!$validation['ok']) {
        //     return ApiResponse::error($validation['message'], 422);
        // }

        return DB::transaction(function () use ($data, $branchId) {
            $subtotal = collect($data['items'])->sum(fn($i) => $i['quantity'] * $i['price']);
            $total = $subtotal - ($data['discount'] ?? 0) + ($data['tax'] ?? 0);

            $sale = Sale::create([
                'invoice_no' => 'INV-' . time(),
                'customer_id' => $data['customer_id'] ?? null,
                'branch_id'  => $branchId,
                'subtotal'   => $subtotal,
                'discount'   => $data['discount'] ?? 0,
                'tax'        => $data['tax'] ?? 0,
                'total'      => $total,
            ]);

            foreach ($data['items'] as $item) {
                $sale->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'price'      => $item['price'],
                    'total'      => $item['quantity'] * $item['price'],
                ]);

                // Deduct stock
                DB::table('product_stocks')
                    ->where('product_id', $item['product_id'])
                    ->where('branch_id', $branchId)
                    ->decrement('quantity', $item['quantity']);

                StockMovement::create([
                    'product_id' => $item['product_id'],
                    'branch_id'  => $branchId,
                    'type'       => 'sale',
                    'quantity'   => -$item['quantity'],
                    'reference'  => $sale->invoice_no,
                ]);
            }

            if (!empty($data['payments'])) {
                foreach ($data['payments'] as $payment) {
                    $sale->payments()->create($payment);
                }
            }

            $this->updateSaleStatus($sale);

            return ApiResponse::success($sale->load('items', 'payments'));
        });
    }

    // Helper to update status
    protected function updateSaleStatus(Sale $sale)
    {
        $paid = $sale->payments()->sum('amount');
        if ($paid >= $sale->total) {
            $sale->update(['status' => 'paid']);
        } elseif ($paid > 0) {
            $sale->update(['status' => 'partial']);
        } else {
            $sale->update(['status' => 'pending']);
        }
    }

    protected function validateStock(int $branchId, array $items): array
    {
        $productIds = collect($items)->pluck('product_id');

        // Fetch stock quantities for this branch
        $stocks = DB::table('product_stocks')
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $productIds)
            ->pluck('quantity', 'product_id'); // product_id => quantity

        // Fetch product names once
        $products = Product::whereIn('id', $productIds)
            ->pluck('name', 'id'); // product_id => name

        foreach ($items as $item) {
            $productId = $item['product_id'];
            $requiredQty = $item['quantity'];
            $available = $stocks[$productId] ?? null;

            if ($available === null) {
                return [
                    'ok' => false,
                    'message' => "No stock record found for " . ($products[$productId] ?? "Product #$productId") . " at branch $branchId"
                ];
            }

            if ($available < $requiredQty) {
                return [
                    'ok' => false,
                    'message' => "Insufficient stock for " . ($products[$productId] ?? "Product #$productId") . " (Available: $available, Requested: $requiredQty)"
                ];
            }
        }

        return ['ok' => true, 'message' => null];
    }
}
