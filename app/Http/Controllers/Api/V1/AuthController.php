<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\User;
use App\Services\BranchContextService;
use App\Services\BranchRoleService;
use App\Services\BranchPermissionStateService;
use App\Services\DeliveryBoyCashService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request, DeliveryBoyCashService $cashService)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'User is inactive'], 403);
        }

        $user->load('roles:id,name');

        // Create Sanctum Token
        $token = $user->createToken('pos-token', ['*'], now()->addDays(6))->plainTextToken;

        $data = [
            'token' => $token,
            'user' => $this->userPayload($user, $cashService),
        ];

        return ApiResponse::success($data, 'Login successful');
    }

    public function me(Request $request, DeliveryBoyCashService $cashService)
    {
        $user = $request->user()->load('roles:id,name');

        return ApiResponse::success($this->userPayload($user, $cashService), 'User fetched successfully');
    }

    public function permissionVersion(Request $request, BranchPermissionStateService $permissionState)
    {
        $branchId = $request->user()?->branch_id ? (int) $request->user()->branch_id : null;
        return ApiResponse::success([
            'branch_id' => $branchId,
            'permission_version' => $permissionState->version($branchId),
        ]);
    }

    public function logout(Request $request)
    {
        // Revoke only the token used for this request, not every token the
        // user has ever created.  Deleting all tokens would invalidate any
        // other device/session that is still active — and, critically, it
        // caused the multi-user desktop bug: after User A logs out their token
        // is already gone, so User B's independent token (issued by login())
        // was also being wiped, leaving User B's subsequent API calls
        // returning 401 "Unauthenticated".
        $request->user()->currentAccessToken()->delete();

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

    private function userPayload(User $user, DeliveryBoyCashService $cashService): array
    {
        $branchRoles = app(BranchRoleService::class);
        $roles = $branchRoles->publicRoleNamesForUser($user);

        $branchContext = app(BranchContextService::class);
        $branchId = $user->branch_id ? (int) $user->branch_id : null;

        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? null,
            'branch_id' => $branchId,
            'branch' => $branchContext->branchPayload($branchId),
            'is_master_admin' => $branchContext->isMasterAdmin($user),
            'is_active' => (bool) $user->is_active,
            'role' => $roles,
            'roles' => $roles,
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'permission_version' => app(BranchPermissionStateService::class)->version($branchId),
        ];

        if ($roles->contains(fn ($role) => User::normalizeRoleName((string) $role) === User::normalizeRoleName('delivery'))) {
            $summary = $cashService->summaryForUser($user);
            $payload['delivery_cash_summary'] = $summary;
            $payload['balance'] = $summary['balance'];
        }

        return $payload;
    }

    public function switchBranch(Request $request, BranchContextService $branchContext, DeliveryBoyCashService $cashService)
    {
        if (!$branchContext->isMasterAdmin($request->user())) {
            throw ValidationException::withMessages([
                'branch_id' => ['Only master admin can switch branches.'],
            ]);
        }

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $request->user()->forceFill([
            'branch_id' => (int) $data['branch_id'],
        ])->save();

        $user = $request->user()->fresh()->load('roles:id,name');

        return ApiResponse::success([
            'user' => $this->userPayload($user, $cashService),
            'active_branch' => $branchContext->branchPayload((int) $data['branch_id']),
        ], 'Branch switched successfully');
    }
}
