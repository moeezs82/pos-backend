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
}
