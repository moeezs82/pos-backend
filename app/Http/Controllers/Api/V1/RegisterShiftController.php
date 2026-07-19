<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CloseRegisterShiftRequest;
use App\Http\Requests\OpenRegisterShiftRequest;
use App\Http\Requests\ShiftCashMovementRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Response\ApiResponse;
use App\Models\Register;
use App\Models\RegisterShift;
use App\Services\BranchContextService;
use App\Services\RegisterShiftService;
use Illuminate\Http\Request;

class RegisterShiftController extends Controller
{
    public function registers(Request $request, BranchContextService $branches) {
        $q = Register::query();
        if (!$request->boolean('include_inactive') || !$request->user()->can('manage-register-shifts')) {
            $q->where('is_active', true);
        }
        $branches->applyToQuery($q, $request);
        return ApiResponse::success($q->orderBy('name')->get());
    }
    public function storeRegister(RegisterRequest $request, BranchContextService $branches) {
        $branchId = $branches->requireBranchId($request);
        $register = Register::create([
            ...$request->validated(),
            'branch_id' => $branchId,
            'code' => strtoupper($request->validated('code')),
            'is_active' => $request->boolean('is_active', true),
        ]);
        return ApiResponse::success($register, 'Register created', 201);
    }
    public function updateRegister(RegisterRequest $request, Register $register, BranchContextService $branches) {
        $branches->assertCanAccessBranch($request, (int) $register->branch_id);
        if (!$request->boolean('is_active', true) && $register->shifts()->where('status', 'open')->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'is_active' => ['Close the active shift before deactivating this register.'],
            ]);
        }
        $register->update([
            ...$request->validated(),
            'code' => strtoupper($request->validated('code')),
            'is_active' => $request->boolean('is_active', true),
        ]);
        return ApiResponse::success($register->fresh(), 'Register updated');
    }
    public function active(Request $request, BranchContextService $branches, RegisterShiftService $service) {
        $q = RegisterShift::with(['register', 'cashier:id,name'])
            ->where('branch_id', $branches->requireBranchId($request))
            ->where('status', 'open')
            ->where(function ($query) use ($request) {
                $query->where('cashier_id', $request->user()->id)
                    ->orWhere('opened_by', $request->user()->id);
            });
        // Recover the authenticated user's own open shift before applying the
        // current branch context. A cashier can only have one open shift, and
        // logout/login or a stale branch selection must not orphan it in UI.
        $shift = $q->latest('opened_at')->first();
        if ($shift) $shift->load(['movements' => fn ($q) => $q->with(['creator:id,name', 'approver:id,name'])->latest('occurred_at')]);

        $occupied = collect();
        if (!$shift) {
            $occupiedQuery = RegisterShift::with(['register', 'cashier:id,name'])
                ->where('status', 'open');
            $branches->applyToQuery($occupiedQuery, $request);
            $occupied = $occupiedQuery->latest('opened_at')->get();
        }

        return ApiResponse::success([
            'shift' => $shift,
            'summary' => $shift ? $service->summary($shift) : null,
            // Unified drawer activity (read-only) so the register screen can show
            // every cash effect without a duplicate manual movement.
            'activity' => $shift ? $service->activity($shift, [
                'page' => (int) $request->get('activity_page', 1),
                'per_page' => (int) $request->get('activity_per_page', 50),
            ]) : null,
            'occupied_shifts' => $occupied,
        ]);
    }
    public function index(Request $request, BranchContextService $branches, RegisterShiftService $service) {
        $q = RegisterShift::with(['register', 'cashier:id,name', 'closedBy:id,name'])->latest('opened_at'); $branches->applyToQuery($q, $request);
        if (!$request->user()->can('manage-register-shifts')) $q->where('cashier_id', $request->user()->id);
        return ApiResponse::success($q->paginate(20));
    }
    public function show(Request $request, RegisterShift $shift, BranchContextService $branches, RegisterShiftService $service) {
        $branches->assertCanAccessBranch($request, $shift->branch_id);
        if ((int)$shift->cashier_id !== (int)$request->user()->id && !$request->user()->can('manage-register-shifts')) abort(403);
        return ApiResponse::success([
            'shift' => $shift->load(['register','cashier:id,name','closedBy:id,name','approvedBy:id,name','movements.creator:id,name','movements.approver:id,name']),
            'summary' => $service->summary($shift),
            'activity' => $service->activity($shift, [
                'page' => (int) $request->get('activity_page', 1),
                'per_page' => (int) $request->get('activity_per_page', 50),
            ]),
        ]);
    }

    /** Dedicated paginated drawer-activity feed for large shifts. */
    public function activity(Request $request, RegisterShift $shift, BranchContextService $branches, RegisterShiftService $service) {
        $branches->assertCanAccessBranch($request, $shift->branch_id);
        if ((int)$shift->cashier_id !== (int)$request->user()->id && !$request->user()->can('manage-register-shifts')) abort(403);
        return ApiResponse::success($service->activity($shift, [
            'page' => (int) $request->get('page', 1),
            'per_page' => (int) $request->get('per_page', 50),
        ]));
    }
    public function open(OpenRegisterShiftRequest $request, RegisterShiftService $service) {
        $shift = $service->open(Register::findOrFail($request->integer('register_id')), $request->user(), $request->validated());
        return ApiResponse::success($shift->load('register'), 'Shift opened', 201);
    }
    public function movement(ShiftCashMovementRequest $request, RegisterShift $shift, RegisterShiftService $service) {
        if ((int)$shift->cashier_id !== (int)$request->user()->id && !$request->user()->can('manage-register-shifts')) abort(403);
        return ApiResponse::success($service->movement($shift, $request->user(), $request->validated()), 'Cash movement recorded', 201);
    }
    public function close(CloseRegisterShiftRequest $request, RegisterShift $shift, RegisterShiftService $service) {
        return ApiResponse::success($service->close($shift, $request->user(), $request->validated()), 'Shift closed');
    }
    public function forceClose(CloseRegisterShiftRequest $request, RegisterShift $shift, RegisterShiftService $service) {
        abort_unless($request->user()->can('manage-register-shifts'), 403);
        return ApiResponse::success($service->close($shift, $request->user(), $request->validated(), true), 'Shift force-closed');
    }
}
