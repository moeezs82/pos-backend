<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Branch;
use App\Models\BranchSubscription;
use App\Models\SubscriptionAudit;
use App\Services\BranchContextService;
use App\Services\BranchAddonService;
use App\Services\SubscriptionStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function __construct(
        private SubscriptionStatusService $subscriptionService,
        private BranchContextService      $branchContext,
        private BranchAddonService        $addons,
    ) {}

    // ── Status check (authenticated, branch = user's active branch) ───────────

    /**
     * GET /subscription/status — returns the subscription state for the
     * caller's current active branch.
     *
     * Master admin may optionally pass ?branch_id=X to query another branch's
     * state (used by the management screen before switching).  Normal users
     * always get their own branch's status.
     *
     * This route is exempt from CheckBranchSubscription so users can query
     * their status even when locked (to display the lock screen data).
     */
    public function status(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        // Master admin may query a specific branch.
        if ($this->branchContext->isMasterAdmin($user) && $request->filled('branch_id')) {
            $branchId = (int) $request->input('branch_id');
            if (!Branch::query()->whereKey($branchId)->exists()) {
                return ApiResponse::error('Branch not found.', 404);
            }
        } else {
            $branchId = $user->branch_id ? (int) $user->branch_id : null;
        }

        if (!$branchId) {
            return ApiResponse::error('No branch selected. Switch to a branch first.', 422);
        }

        $result = array_merge($this->subscriptionService->evaluate($branchId), [
            'addons' => $this->addons->activeMap($branchId),
        ]);
        return ApiResponse::success($result);
    }

    // ── Owner management routes ───────────────────────────────────────────────
    // All methods below require isMasterAdmin(); normal branch admins receive 403.

    /**
     * GET /subscriptions — list all branches with computed subscription status.
     *
     * Supports: ?search=name, ?status=active|trial|grace_period|expired|suspended|not_configured, ?page=N
     *
     * Response includes:
     *   - paginated branch list with computed fields
     *   - summary counts (total, by status, expiring_soon, not_configured)
     */
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->requireOwner($request);

        $query = Branch::query()
            ->leftJoin('branch_subscriptions as bs', 'branches.id', '=', 'bs.branch_id')
            ->select(
                'branches.id',
                'branches.name',
                'branches.location',
                'branches.is_active',
                'bs.status as subscription_status',
                'bs.expires_at',
                'bs.grace_until',
                'bs.started_at',
                'bs.suspended_reason',
                'bs.notes',
                'bs.managed_by',
                'bs.updated_at as subscription_updated_at',
            )
            ->whereNull('branches.deleted_at')
            ->orderBy('branches.name');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('branches.name', 'like', "%{$search}%");
        }

        // 'not_configured' is a computed status (no row in branch_subscriptions).
        if ($request->filled('status')) {
            $statusFilter = $request->input('status');
            if ($statusFilter === 'not_configured') {
                $query->whereNull('bs.status');
            } else {
                $query->where('bs.status', $statusFilter);
            }
        }

        $branches = $query->paginate(25);

        // Build computed fields for every page row.
        $managerIds = $branches->getCollection()
            ->pluck('managed_by')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $managers = $managerIds
            ? DB::table('users')
                ->whereIn('id', $managerIds)
                ->pluck('name', 'id')
            : collect();

        $pageAddonMaps = $this->addons->activeMaps(
            $branches->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        $items = $branches->getCollection()->map(function ($row) use ($managers, $pageAddonMaps) {
            $computed = $this->subscriptionService->evaluate((int) $row->id);
            return array_merge($row->toArray(), [
                'computed_status'      => $computed['status'],
                'is_locked'            => $computed['is_locked'],
                'remaining_days'       => $computed['remaining_days'],
                'show_alert'           => $computed['show_alert'],
                'message'              => $computed['message'],
                'last_updated_by'      => $row->managed_by
                    ? ($managers[$row->managed_by] ?? null)
                    : null,
                'addons'               => $pageAddonMaps[(int) $row->id] ?? [
                    BranchAddonService::BARCODE_LABELS => false,
                ],
            ]);
        });

        // ── Summary counts ────────────────────────────────────────────────────
        // Compute over ALL branches (not just the current page) so the dashboard
        // cards always show totals even when the list is filtered or paginated.
        $allBranchIds = Branch::whereNull('deleted_at')->pluck('id');
        $summary      = $this->buildSummary($allBranchIds->all());
        $allAddonMaps = $this->addons->activeMaps($allBranchIds->map(fn ($id) => (int) $id)->all());
        $summary['barcode_labels_addon'] = collect($allAddonMaps)
            ->filter(fn (array $map) => (bool) ($map[BranchAddonService::BARCODE_LABELS] ?? false))
            ->count();

        return ApiResponse::success([
            'branches' => $branches->setCollection($items),
            'summary'  => $summary,
        ]);
    }

    /**
     * Build subscription summary counts across the given branch IDs.
     */
    private function buildSummary(array $branchIds): array
    {
        $alertDays = max(1, (int) (config('subscription.alert_days') ?? env('SUBSCRIPTION_ALERT_DAYS', 7)));
        $now       = now();

        // Pull raw data in one query rather than N service calls.
        $subs = BranchSubscription::whereIn('branch_id', $branchIds)
            ->get(['branch_id', 'status', 'expires_at', 'grace_until'])
            ->keyBy('branch_id');

        $counts = [
            'total'          => count($branchIds),
            'not_configured' => 0,
            'active'         => 0,
            'trial'          => 0,
            'grace_period'   => 0,
            'expiring_soon'  => 0,
            'expired'        => 0,
            'suspended'      => 0,
            'locked'         => 0,
        ];

        foreach ($branchIds as $id) {
            if (!isset($subs[$id])) {
                $counts['not_configured']++;
                $counts['locked']++;
                continue;
            }

            $sub    = $subs[$id];
            $status = $sub->status;

            if ($status === 'suspended') {
                $counts['suspended']++;
                $counts['locked']++;
                continue;
            }

            if ($status === 'expired') {
                $counts['expired']++;
                $counts['locked']++;
                continue;
            }

            if ($status === 'grace_period') {
                $graceCutoff = $sub->grace_until ?? $sub->expires_at;
                if ($graceCutoff && $now->gt($graceCutoff)) {
                    $counts['expired']++;
                    $counts['locked']++;
                } else {
                    $counts['grace_period']++;
                }
                continue;
            }

            // trial / active — check date expiry
            if ($sub->expires_at && $now->gt($sub->expires_at)) {
                $counts['expired']++;
                $counts['locked']++;
                continue;
            }

            if ($sub->expires_at) {
                $daysLeft = (int) ceil($now->diffInSeconds($sub->expires_at, false) / 86400);
                if ($daysLeft <= $alertDays) {
                    $counts['expiring_soon']++;
                }
            }

            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * GET /subscriptions/{branch} — full subscription detail for one branch.
     */
    public function show(Request $request, int $branchId): \Illuminate\Http\JsonResponse
    {
        $this->requireOwner($request);

        $branch = Branch::findOrFail($branchId);
        $sub    = BranchSubscription::where('branch_id', $branchId)->first();
        $status = $this->subscriptionService->evaluate($branchId);

        return ApiResponse::success([
            'branch'       => $branch->only(['id', 'name', 'location', 'is_active']),
            'subscription' => $sub,
            'status'       => $status,
            'addons'       => $this->addons->catalogForBranch($branchId),
        ]);
    }

    /**
     * PUT /subscriptions/{branch} — set, extend, suspend, or reactivate.
     *
     * Accepted fields:
     *   status           string  one of BranchSubscription::STATUSES
     *   expires_at       string  ISO-8601 or Y-m-d; null clears it (no expiry)
     *   grace_until      string  ISO-8601 or Y-m-d; null clears grace period
     *   started_at       string  optional subscription start date
     *   suspended_reason string  required when status = 'suspended'
     *   notes            string  optional owner notes
     *   reason           string  audit reason (not stored on subscription itself)
     */
    public function update(Request $request, int $branchId): \Illuminate\Http\JsonResponse
    {
        $this->requireOwner($request);

        $branch = Branch::findOrFail($branchId);

        $data = $request->validate([
            'status'           => ['sometimes', 'string', 'in:' . implode(',', BranchSubscription::STATUSES)],
            'expires_at'       => ['sometimes', 'nullable', 'date'],
            'grace_until'      => ['sometimes', 'nullable', 'date'],
            'started_at'       => ['sometimes', 'nullable', 'date'],
            'suspended_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'notes'            => ['sometimes', 'nullable', 'string'],
            'reason'           => ['sometimes', 'nullable', 'string', 'max:1000'],
            'addons'           => ['sometimes', 'array'],
            'addons.barcode_labels' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['status']) && $data['status'] === 'suspended' && empty($data['suspended_reason'])) {
            return ApiResponse::error("A reason is required when suspending a branch.", 422);
        }

        // Renewing an expired subscription: if new status is active/trial and no
        // explicit expires_at is given, keep or require one from the operator.
        // We do NOT silently set a fake expiry — the SaaS Owner must be deliberate.

        $sub = DB::transaction(function () use ($branchId, $branch, $data, $request) {
            $sub = BranchSubscription::where('branch_id', $branchId)->lockForUpdate()->first();
            $old = $sub ? $sub->toArray() : null;

            if (!$sub) {
                $sub = new BranchSubscription(['branch_id' => $branchId]);
            }

            $newStatus = $data['status'] ?? $sub->status ?? 'active';
            $action = $this->auditAction($sub->status ?? null, $newStatus);

            foreach (['status', 'expires_at', 'grace_until', 'started_at', 'suspended_reason', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $sub->{$field} = $data[$field];
                }
            }

            if (isset($data['status']) && $data['status'] !== 'suspended'
                && !isset($data['suspended_reason'])) {
                $sub->suspended_reason = null;
            }

            $sub->managed_by = $request->user()->id;
            $sub->save();

            SubscriptionAudit::create([
                'branch_id'      => $branchId,
                'changed_by'     => $request->user()->id,
                'old_status'     => $old['status'] ?? null,
                'new_status'     => $sub->status,
                'old_expires_at' => $old['expires_at'] ?? null,
                'new_expires_at' => $sub->expires_at,
                'old_grace_until'=> $old['grace_until'] ?? null,
                'new_grace_until'=> $sub->grace_until,
                'action'         => $action,
                'reason'         => $data['reason'] ?? null,
                'metadata'       => [
                    'branch_name'  => $branch->name,
                    'changed_by'   => $request->user()->name,
                    'old_snapshot' => $old,
                ],
            ]);

            if (array_key_exists('addons', $data)) {
                $this->addons->updateMany(
                    $branchId,
                    $data['addons'],
                    (int) $request->user()->id,
                    $data['reason'] ?? null,
                    ['branch_name' => $branch->name]
                );
            }

            return $sub;
        });

        $status = $this->subscriptionService->evaluate($branchId);

        return ApiResponse::success([
            'subscription' => $sub->fresh(),
            'status'       => $status,
            'addons'       => $this->addons->catalogForBranch($branchId),
        ], 'Subscription updated successfully.');
    }

    /**
     * GET /subscriptions/{branch}/audit — audit history for one branch,
     * newest first, paginated.
     */
    public function audit(Request $request, int $branchId): \Illuminate\Http\JsonResponse
    {
        $this->requireOwner($request);
        Branch::findOrFail($branchId); // 404 if not found

        $entries = SubscriptionAudit::where('branch_id', $branchId)
            ->with('changedBy:id,name,email')
            ->orderByDesc('created_at')
            ->paginate(20);

        return ApiResponse::success($entries);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function requireOwner(Request $request): void
    {
        if (!$this->branchContext->isMasterAdmin($request->user())) {
            abort(403, 'Only the platform owner can manage branch subscriptions.');
        }
    }

    private function auditAction(?string $oldStatus, string $newStatus): string
    {
        if ($oldStatus === null) return 'create';
        if ($newStatus === 'suspended') return 'suspend';
        if ($oldStatus === 'suspended' && in_array($newStatus, ['active', 'trial'])) return 'reactivate';
        if (in_array($oldStatus, ['expired', 'grace_period']) && in_array($newStatus, ['active', 'trial'])) return 'renew';
        if ($newStatus === 'grace_period') return 'set_grace_period';
        if ($newStatus === 'expired') return 'set_expired';
        return 'set_expiry';
    }
}
