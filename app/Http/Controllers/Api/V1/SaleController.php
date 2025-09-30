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
        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
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
        $sale = Sale::with(['customer:id,first_name,last_name', 'branch', 'items.product:id,name', 'payments', 'vendor:id,first_name,last_name', 'salesman:id,name'])->findOrFail($id);

        return ApiResponse::success($sale);
    }

    public function update(Request $request, $id)
    {
        // Only allow updating discount & tax from this endpoint (as per your UI)
        $data = $request->validate([
            'discount' => 'nullable|numeric|min:0',
            'tax'      => 'nullable|numeric|min:0',
        ]);

        // Load sale with items once
        $sale = Sale::with(['items', 'payments'])->findOrFail($id);

        // Optional: block edits for certain statuses (adjust to your app’s statuses)
        if (in_array($sale->status, ['cancelled', 'void', 'returned'])) {
            return ApiResponse::error("This sale can't be edited in its current status.", 422);
        }

        return DB::transaction(function () use ($sale, $data) {
            // Recompute subtotal from existing items
            $subtotal = $sale->items->sum(function ($i) {
                return ((float) $i->quantity) * ((float) $i->price);
            });

            // New discount/tax (fallback to current values if not provided)
            $discount = array_key_exists('discount', $data)
                ? (float) $data['discount']
                : (float) $sale->discount;

            $tax = array_key_exists('tax', $data)
                ? (float) $data['tax']
                : (float) $sale->tax;

            // Compute total (never below zero)
            $total = max(0, $subtotal - $discount + $tax);

            // Persist
            $sale->update([
                'subtotal' => round($subtotal, 2),
                'discount' => round($discount, 2),
                'tax'      => round($tax, 2),
                'total'    => round($total, 2),
            ]);

            // Re-evaluate status after totals change
            $this->updateSaleStatus($sale);

            // Return fresh copy
            return ApiResponse::success($sale->fresh(['items', 'payments']));
        });
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'salesman_id' => 'nullable|exists:users,id',
            'created_by' => 'nullable|exists:users,id',
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
                'vendor_id' => $data['vendor_id'] ?? null,
                'salesman_id' => $data['salesman_id'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
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
