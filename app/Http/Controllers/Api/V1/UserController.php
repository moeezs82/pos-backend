<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Response\ApiResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        // $request->validate([
        //     // 'branch_id' => 'nullable|exists:branches,id'
        // ]);

        $q = User::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search')->toString();
                $q->where(fn($qq) => $qq->where('name', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")
                    ->orWhere('phone', 'like', "%{$s}%"));
            })
            ->when($request->filled('role'), function ($q) use ($request) {
                $roleName = $request->string('role')->toString();

                $q->whereHas('roles', function ($rq) use ($roleName) {
                    $rq->where('name', $roleName);
                });
            })
            // ->when($request->filled('branch_id'), function ($q) use ($request) {
            //     $branchId = $request->string('branch_id');
            //     $q->where(function ($q) use ($branchId) {
            //         $q->where('branch_id', $branchId)
            //             ->orWhereNull('branch_id');
            //     });
            // })
            ->with(['roles:id,name', 'permissions:id,name']);

        return ApiResponse::success($q->paginate($request->integer('per_page', 20)));
    }

    public function store(UserRequest $request)
    {
        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);

        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'phone'     => $data['phone'] ?? null,
            'password'  => $data['password'],
            // 'branch_id' => $data['branch_id'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        // Optional: sync roles/permissions during creation
        if (!empty($data['roles'])) {
            $user->syncRoles($data['roles']);
        }

        return ApiResponse::success($user->load('roles:id,name'), null, 201);
    }

    public function show(User $user)
    {
        return ApiResponse::success($user->load('roles:name'));
    }

    public function update(UserRequest $request, User $user)
    {
        $data = $request->validated();

        $payload = [
            'name'      => $data['name']      ?? $user->name,
            'email'     => $data['email']     ?? $user->email,
            'phone'     => $data['phone']     ?? $user->phone,
            'is_active' => $data['is_active'] ?? $user->is_active,
        ];
        if (!empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        $user->update($payload);

        // Optional: allow resync via update
        if (array_key_exists('roles', $data)) {
            $user->syncRoles($data['roles'] ?? []);
        }

        return ApiResponse::success($user->load('roles:id,name'), null, 201);
    }

    public function destroy(User $user)
    {
        $user->tokens()->delete();
        $user->delete();
        return response()->json(['message' => 'User deleted']);
    }

    public function syncRoles(Request $request, User $user)
    {
        $data = $request->validate(['roles' => ['array'], 'roles.*' => ['string']]);
        $user->syncRoles($data['roles'] ?? []);
        return ApiResponse::success($user->getRoleNames());
    }

    public function deliveryBoyCashSummary(Request $request, User $user)
    {
        // Optional filters
        $from = $request->date('from'); // YYYY-MM-DD
        $to   = $request->date('to');   // YYYY-MM-DD

        $ordersQuery = $user->deliveryOrders()
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to,   fn ($q) => $q->whereDate('created_at', '<=', $to));

        $receivedQuery = $user->deliveryBoyReceived()
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to,   fn ($q) => $q->whereDate('created_at', '<=', $to));

        $ordersTotal   = (clone $ordersQuery)->sum('total');
        $receivedTotal = (clone $receivedQuery)->sum('amount');

        $data = [
            'delivery_boy'   => ['id' => $user->id, 'name' => $user->name],
            'filters'        => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'orders_total'   => (float) $ordersTotal,
            'received_total' => (float) $receivedTotal,
            'balance'        => (float) ($ordersTotal - $receivedTotal),

            // Optional details (remove if you only want totals)
            'orders'   => $ordersQuery->latest()->paginate($request->integer('orders_per_page', 20)),
            'received' => $receivedQuery->latest()->paginate($request->integer('received_per_page', 20)),
        ];

        return ApiResponse::success($data);
    }
}
