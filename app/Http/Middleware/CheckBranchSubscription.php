<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionStatusService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces branch-level subscription entitlement on every branch-operational
 * API route.
 *
 * PLACEMENT
 * Applied to the main auth:sanctum + branch.context route group.  Routes that
 * must remain accessible even when the subscription is expired or suspended use
 * ->withoutMiddleware('branch.subscription') individually:
 *   - POST /logout
 *   - GET  /me
 *   - POST /switch-branch
 *   - GET  /branches  (list — needed to switch to another active branch)
 *   - GET  /subscription/status  (the check itself must not be blocked)
 *   - GET  /app-lock-status      (backward-compat alias — same reason)
 *   - All  /subscriptions/*      (owner management — has its own owner gate)
 *
 * SaaS OWNER
 * The master admin IS blocked on expired-branch operational routes, just like
 * any other user.  They use the exempt /subscriptions/* routes to reactivate
 * the branch and the exempt /switch-branch to move to another active branch.
 * This is intentional: the master admin should not be able to bypass expiry
 * for POS operations without reactivating the subscription first.
 *
 * MACHINE-READABLE ERROR CODES
 * The Flutter client distinguishes two locked states in the response body:
 *   BRANCH_SUBSCRIPTION_NOT_CONFIGURED — no subscription row exists for the branch.
 *   BRANCH_SUBSCRIPTION_EXPIRED        — subscription row exists but is expired/suspended.
 *
 * Both use HTTP 402 so the client can apply a single status-code check and then
 * read the 'code' field to display the correct message on the lock screen.
 */
class CheckBranchSubscription
{
    public function __construct(private SubscriptionStatusService $subscriptionService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // If there's no authenticated user or no effective branch, the auth
        // middleware should have handled it — just pass through.
        if (!$user || !$user->branch_id) {
            return $next($request);
        }

        $branchId = (int) $user->branch_id;
        $result   = $this->subscriptionService->evaluate($branchId);

        if ($result['is_locked']) {
            // Distinguish "never configured" from "was active but now expired/suspended"
            // so the Flutter lock screen and sync layer can surface the right message.
            $code = $result['status'] === 'not_configured'
                ? 'BRANCH_SUBSCRIPTION_NOT_CONFIGURED'
                : 'BRANCH_SUBSCRIPTION_EXPIRED';

            return response()->json([
                'success' => false,
                'code'    => $code,
                'message' => $result['message'],
                'data'    => $result,
            ], 402);
        }

        return $next($request);
    }
}
