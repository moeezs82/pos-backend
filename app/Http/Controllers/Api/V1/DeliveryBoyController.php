<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Sale;
use App\Models\DeliveryBoyReceived;

class DeliveryBoyController extends Controller
{
    /**
     * GET /delivery-boys/{id}/cash-summary
     * Returns: delivery boy info + orders_total + received_total + balance
     */
    public function cashSummary(Request $request, int $id)
    {
        // $branchId = $request->integer('branch_id');
        $from     = $request->input('from'); // YYYY-MM-DD
        $to       = $request->input('to');   // YYYY-MM-DD

        $user = User::query()->findOrFail($id);

        $ordersQ = Sale::query()->where('delivery_boy_id', $id);
        $recvQ   = DeliveryBoyReceived::query()->where('user_id', $id);

        // if ($branchId) {
        //     // if your Sale table column is different, update it
        //     $ordersQ->where('branch_id', $branchId);
        //     // if your received table has branch_id, keep this; otherwise remove
        //     if (schema_has_column('delivery_boy_received', 'branch_id')) {
        //         $recvQ->where('branch_id', $branchId);
        //     }
        // }

        if ($from) {
            $ordersQ->whereDate('created_at', '>=', $from);
            $recvQ->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $ordersQ->whereDate('created_at', '<=', $to);
            $recvQ->whereDate('created_at', '<=', $to);
        }

        $ordersTotal   = (float) $ordersQ->sum('total');
        $receivedTotal = (float) $recvQ->sum('amount');

        return ApiResponse::success([
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'phone'          => $user->phone,
            'status'         => $user->status ?? 'active',
            'orders_total'   => $ordersTotal,
            'received_total' => $receivedTotal,
            'balance'        => $ordersTotal - $receivedTotal,
        ]);
    }

    /**
     * GET /delivery-boys/{id}/orders?page=1&per_page=10
     * Returns paginated orders list (Sale)
     */
    public function orders(Request $request, int $id)
    {
        $perPage  = max(1, min(200, $request->integer('per_page', 10)));
        $branchId = $request->integer('branch_id');
        $from     = $request->input('from');
        $to       = $request->input('to');

        $q = Sale::query()
            ->where('delivery_boy_id', $id)
            // ->when($branchId, fn ($qq) => $qq->where('branch_id', $branchId))
            ->when($from, fn ($qq) => $qq->whereDate('created_at', '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate('created_at', '<=', $to))
            ->latest('id');

        $p = $q->paginate($perPage);

        return ApiResponse::success([
            'items'        => $p->items(),
            'current_page' => $p->currentPage(),
            'last_page'    => $p->lastPage(),
            'total'        => $p->total(),
        ]);
    }

    /**
     * GET /delivery-boys/{id}/received?page=1&per_page=10
     * Returns paginated received entries list
     */
    public function received(Request $request, int $id)
    {
        $perPage  = max(1, min(200, $request->integer('per_page', 10)));
        $branchId = $request->integer('branch_id');
        $from     = $request->input('from');
        $to       = $request->input('to');

        $q = DeliveryBoyReceived::query()
            ->where('user_id', $id)
            // ->when($branchId, function ($qq) use ($branchId) {
            //     // if your table has branch_id
            //     if (schema_has_column('delivery_boy_received', 'branch_id')) {
            //         $qq->where('branch_id', $branchId);
            //     }
            // })
            ->when($from, fn ($qq) => $qq->whereDate('created_at', '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate('created_at', '<=', $to))
            ->latest('id');

        $p = $q->paginate($perPage);

        return ApiResponse::success([
            'items'        => $p->items(),
            'current_page' => $p->currentPage(),
            'last_page'    => $p->lastPage(),
            'total'        => $p->total(),
        ]);
    }

    /**
     * POST /delivery-boys/{id}/received
     * Body: amount, method (cash|bank), reference?, branch_id?
     */
    public function storeReceived(Request $request, int $id)
    {
        $data = $request->validate([
            'amount'    => ['required', 'numeric', 'gt:0']
        ]);

        $row = new DeliveryBoyReceived();
        $row->user_id    = $id;
        $row->amount     = $data['amount'];

        $row->save();

        return ApiResponse::success($row, 'Received recorded');
    }
}

/**
 * Small helper: check if column exists without crashing.
 * Put this in a global helpers file if you want.
 */
if (!function_exists('schema_has_column')) {
    function schema_has_column(string $table, string $column): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
