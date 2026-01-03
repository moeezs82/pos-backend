<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string'
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'User is inactive'], 403);
        }

        // Create Sanctum Token
        $token = $user->createToken('pos-token')->plainTextToken;

        $data = [
            'token' => $token,
            'user'  => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->getRoleNames(), // from Spatie
            ]
        ];
        return ApiResponse::success($data, 'Login successful');
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return ApiResponse::success(null, 'Logged out successfully');
    }

    public function verifyPassword(Request $request)
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user(); // ✅ from Bearer token auth middleware
        if (!$user) {
            return ApiResponse::error('Unauthenticated', 401);
        }

        $ok = Hash::check($data['password'], $user->password);

        return ApiResponse::success([
            'ok' => $ok,
        ], $ok ? 'OK' : 'Invalid password');
    }
}
