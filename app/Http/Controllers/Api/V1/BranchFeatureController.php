<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Branch;
use App\Services\BranchContextService;
use App\Services\BranchFeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Branch feature flag API.
 *
 * ─────────────────────────────────────────────────────
 *  GET  /api/v1/branch-features/current
 *       Returns effective features for the caller's active branch.
 *       Any authenticated branch user (read-only, own branch only).
 *
 *  GET  /api/v1/branches/{branch}/features
 *       Returns effective features for a specific branch.
 *       Master Admin only.
 *
 *  PUT  /api/v1/branches/{branch}/features
 *       Updates feature flags for a branch.
 *       Master Admin only. Blocked while delivery custody is non-zero.
 * ─────────────────────────────────────────────────────
 */
class BranchFeatureController extends Controller
{
    public function __construct(
        private BranchContextService $branchContext,
        private BranchFeatureService $featureService,
    ) {}

    // ── GET /branch-features/current ─────────────────────────────────────

    /**
     * Effective features for the caller's own active branch.
     * Normal users call this on app start / branch switch.
     */
    public function current(Request $request): JsonResponse
    {
        $branchId = $this->branchContext->requireBranchId($request);
        $features = $this->featureService->forBranch($branchId);

        return ApiResponse::success($this->payload($branchId, $features));
    }

    // ── GET /branches/{branch}/features ──────────────────────────────────

    /**
     * Features for a specific branch. Master Admin only.
     */
    public function show(Request $request, int $branch): JsonResponse
    {
        $this->requireMasterAdmin($request);
        $this->assertBranchExists($branch);

        $features = $this->featureService->forBranch($branch);

        return ApiResponse::success($this->payload($branch, $features));
    }

    // ── PUT /branches/{branch}/features ──────────────────────────────────

    /**
     * Update feature flags for a branch. Master Admin only.
     *
     * Delivery safety check:
     *   If delivery_enabled is being set to false and account 1210 has
     *   a non-zero custody balance, the request is rejected.  Delivery boys
     *   must settle before the module can be disabled, otherwise the
     *   settlement UI would be hidden before the balance is cleared.
     */
    public function update(Request $request, int $branch): JsonResponse
    {
        $this->requireMasterAdmin($request);
        $this->assertBranchExists($branch);

        $data = $request->validate([
            'delivery_enabled'    => 'sometimes|boolean',
            'sale_vendor_enabled' => 'sometimes|boolean',
        ]);

        // Strip any keys that aren't the two allowed flags.
        $allowed = array_intersect_key($data, array_flip(['delivery_enabled', 'sale_vendor_enabled']));

        if (empty($allowed)) {
            return ApiResponse::error(
                'No valid feature keys supplied. Allowed: delivery_enabled, sale_vendor_enabled.',
                422
            );
        }

        // Safety check: block delivery disable when custody is non-zero.
        if (isset($allowed['delivery_enabled']) && !(bool) $allowed['delivery_enabled']) {
            $custody = $this->featureService->deliveryCustodyBalance($branch);
            if ($custody > 0.005) {
                throw ValidationException::withMessages([
                    'delivery_enabled' => [
                        "Cannot disable the delivery module while delivery boys hold outstanding "
                        . "cash in custody (account 1210 balance: {$custody}). "
                        . "Collect and settle all delivery cash before disabling this module.",
                    ],
                ]);
            }
        }

        $next = $this->featureService->update($branch, $allowed, $request->user()->id);

        return ApiResponse::success(
            $this->payload($branch, $next),
            'Branch feature settings updated.'
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function payload(int $branchId, array $features): array
    {
        $row = \App\Models\BranchFeatureSetting::query()
            ->where('branch_id', $branchId)
            ->first(['updated_at']);

        return [
            'branch_id' => $branchId,
            'features'  => $features,
            'updated_at' => $row?->updated_at?->toIso8601String(),
        ];
    }

    private function requireMasterAdmin(Request $request): void
    {
        if (!$this->branchContext->isMasterAdmin($request->user())) {
            abort(403, 'Only Master Admin can manage branch feature settings.');
        }
    }

    private function assertBranchExists(int $branchId): void
    {
        if (!Branch::query()->whereKey($branchId)->exists()) {
            abort(404, 'Branch not found.');
        }
    }
}
