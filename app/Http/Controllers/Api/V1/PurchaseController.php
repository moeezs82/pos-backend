<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Purchase;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    // List all purchases
    public function index(Request $request)
    {
        $query = Purchase::with(['vendor', 'branch'])
            ->withSum('payments as paid_amount', 'amount');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_no', 'like', "%$search%")
                    ->orWhereHas('vendor', function ($v) use ($search) {
                        $v->where('first_name', 'like', "%$search%")
                            ->where('last_name', 'like', "%$search%")
                            ->orWhere('email', 'like', "%$search%")
                            ->orWhere('phone', 'like', "%$search%");
                    });
            });
        }

        $request->sort_by === 'total'
            ? $query->orderBy('total', 'desc')
            : $query->orderBy('created_at', 'desc');

        $purchases = $query->paginate($request->get('per_page', 15));

        return ApiResponse::success($purchases);
    }

    // Single purchase
    public function show(Purchase $purchase)
    {
        $purchase->load(['vendor', 'branch', 'items.product', 'payments']);
        return ApiResponse::success($purchase);
    }

    // Create PO (optionally receive now)
    public function store(Request $request)
    {
        $data = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'branch_id' => 'required|exists:branches,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.price'      => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'tax'      => 'nullable|numeric|min:0',
            'expected_at' => 'nullable|date',
            'notes'    => 'nullable|string',
            'receive_now' => 'boolean',
            'items.*.received_qty' => 'nullable|integer|min:0', // used only if receive_now = true
            'payments' => 'array',
            'payments.*.method' => 'nullable|string',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'payments.*.tx_ref' => 'nullable|string',
            'payments.*.paid_at' => 'nullable|date',
            'payments.*.meta'   => 'nullable|array',
        ]);

        $branchId = (int) $data['branch_id'];
        $receiveNow = (bool) ($data['receive_now'] ?? false);

        return DB::transaction(function () use ($data, $branchId, $receiveNow) {
            $subtotal = collect($data['items'])->sum(fn($i) => $i['quantity'] * $i['price']);
            $total    = $subtotal - ($data['discount'] ?? 0) + ($data['tax'] ?? 0);

            $purchase = Purchase::create([
                'invoice_no'    => $this->generateNumber('PUR'),
                'vendor_id'     => $data['vendor_id'],
                'branch_id'     => $branchId,
                'subtotal'      => $subtotal,
                'discount'      => $data['discount'] ?? 0,
                'tax'           => $data['tax'] ?? 0,
                'total'         => $total,
                'status'        => 'pending',   // payment status
                'receive_status' => $receiveNow ? 'partial' : 'ordered',
                'expected_at'   => $data['expected_at'] ?? null,
                'notes'         => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $row = $purchase->items()->create([
                    'product_id'  => $item['product_id'],
                    'quantity'    => $item['quantity'],
                    'received_qty' => 0,
                    'price'       => $item['price'],
                    'total'       => $item['quantity'] * $item['price'],
                ]);

                if ($receiveNow) {
                    $rcv = isset($item['received_qty']) ? (int)$item['received_qty'] : (int)$item['quantity'];
                    $rcv = max(0, min($rcv, (int)$item['quantity'])); // clamp
                    if ($rcv > 0) {
                        $this->incrementStock($item['product_id'], $branchId, $rcv);

                        $row->update(['received_qty' => $rcv]);

                        StockMovement::create([
                            'product_id' => $item['product_id'],
                            'branch_id'  => $branchId,
                            'type'       => 'purchase',
                            'quantity'   => $rcv,
                            'reference'  => $purchase->invoice_no,
                        ]);
                    }
                }
            }

            // Payments (optional on creation)
            if (!empty($data['payments'])) {
                foreach ($data['payments'] as $pay) {
                    $purchase->payments()->create($pay);
                }
            }

            $this->updatePaymentStatus($purchase);
            $this->updateReceiveStatus($purchase);

            return ApiResponse::success($purchase->load('items.product', 'payments'));
        });
    }

    // Receive goods (partial allowed)
    public function receive(Request $request, Purchase $purchase)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.receive_qty' => 'required|integer|min:1',
            'reference' => 'nullable|string', // GRN
            'received_at' => 'nullable|date',
        ]);

        if ($purchase->receive_status === 'cancelled') {
            return ApiResponse::error('Purchase is cancelled, receiving not allowed.', 422);
        }

        return DB::transaction(function () use ($purchase, $data) {
            $branchId  = $purchase->branch_id;
            $grnNumber = $data['reference'] ?? $this->generateNumber('GRN');

            // Map items by product_id for quick access
            $itemsMap = $purchase->items()->get()->keyBy('product_id');

            foreach ($data['items'] as $in) {
                $productId = (int) $in['product_id'];
                $receive   = (int) $in['receive_qty'];

                /** @var PurchaseItem|null $pi */
                $pi = $itemsMap->get($productId);
                if (!$pi) {
                    return ApiResponse::error("Product $productId is not part of this purchase.", 422);
                }

                $remaining = $pi->quantity - $pi->received_qty;
                if ($remaining <= 0) {
                    return ApiResponse::error("Product {$productId} already fully received.", 422);
                }
                if ($receive > $remaining) {
                    return ApiResponse::error("Receive qty ($receive) exceeds remaining ($remaining) for product {$productId}.", 422);
                }

                // Increase stock
                $this->incrementStock($productId, $branchId, $receive);

                // Update item received qty
                $pi->increment('received_qty', $receive);

                // Movement
                StockMovement::create([
                    'product_id' => $productId,
                    'branch_id'  => $branchId,
                    'type'       => 'purchase',
                    'quantity'   => $receive,
                    'reference'  => $grnNumber,
                ]);
            }

            $this->updateReceiveStatus($purchase->fresh('items'));

            return ApiResponse::success($purchase->load('items.product'));
        });
    }

    // Add a payment
    public function addPayment(Request $request, Purchase $purchase)
    {
        $data = $request->validate([
            'method' => 'nullable|string',
            'amount' => 'required|numeric|min:0.01',
            'tx_ref' => 'nullable|string',
            'paid_at' => 'nullable|date',
            'meta'   => 'nullable|array',
        ]);

        return DB::transaction(function () use ($purchase, $data) {
            $purchase->payments()->create($data);
            $this->updatePaymentStatus($purchase->fresh());
            return ApiResponse::success($purchase->load('payments'));
        });
    }

    // Cancel PO (only if nothing received)
    public function cancel(Purchase $purchase)
    {
        $receivedAny = $purchase->items()->sum('received_qty') > 0;
        if ($receivedAny) {
            return ApiResponse::error('Cannot cancel: items already received.', 422);
        }

        $purchase->update(['receive_status' => 'cancelled']);
        return ApiResponse::success($purchase);
    }

    /* ===================== Helpers ===================== */

    protected function generateNumber(string $prefix): string
    {
        return $prefix . '-' . time();
    }

    protected function updatePaymentStatus(Purchase $purchase): void
    {
        $paid = (float) $purchase->payments()->sum('amount');
        if ($paid >= (float) $purchase->total) {
            $purchase->update(['status' => 'paid']);
        } elseif ($paid > 0) {
            $purchase->update(['status' => 'partial']);
        } else {
            $purchase->update(['status' => 'pending']);
        }
    }

    protected function updateReceiveStatus(Purchase $purchase): void
    {
        $items = $purchase->items;
        $totalOrdered  = (int) $items->sum('quantity');
        $totalReceived = (int) $items->sum('received_qty');

        $status = 'ordered';
        if ($totalReceived === 0) {
            $status = 'ordered';
        } elseif ($totalReceived < $totalOrdered) {
            $status = 'partial';
        } else {
            $status = 'received';
        }

        if ($purchase->receive_status !== 'cancelled') {
            $purchase->update(['receive_status' => $status]);
        }
    }

    /**
     * Increment product stock for a branch. Creates row if missing.
     */
    protected function incrementStock(int $productId, int $branchId, int $qty): void
    {
        $affected = DB::table('product_stocks')
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->increment('quantity', $qty);

        if ($affected === 0) {
            DB::table('product_stocks')->insert([
                'product_id' => $productId,
                'branch_id'  => $branchId,
                'quantity'   => $qty,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
