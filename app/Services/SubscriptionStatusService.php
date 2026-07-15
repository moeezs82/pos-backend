<?php

namespace App\Services;

use App\Models\BranchSubscription;
use Carbon\Carbon;

/**
 * Single canonical source of truth for a branch's subscription state.
 *
 * All enforcement decisions (middleware, API responses, Flutter UI) must use
 * this service so the lock/unlock boundary is computed in exactly one place.
 *
 * NEVER TRUST CLIENT-SUPPLIED DATES OR FLAGS.
 * All calculations use server time (now()) according to config('app.timezone').
 *
 * ALERT THRESHOLD
 * Configurable via SUBSCRIPTION_ALERT_DAYS env var; defaults to 7 days.  Set it
 * in .env rather than hard-coding so the operator can adjust without a deploy.
 *
 * RETURN SHAPE
 * {
 *   "branch_id":     12,
 *   "status":        "active",          // canonical computed status
 *   "is_locked":     false,
 *   "expires_at":    "2026-12-31T23:59:59+05:00",  // null when no expiry
 *   "remaining_days": 7,                // null when no expiry
 *   "show_alert":    true,
 *   "message":       "Subscription expires in 7 day(s). Please contact support."
 * }
 */
class SubscriptionStatusService
{
    /** Days before expiry at which a warning is surfaced. */
    private function alertThreshold(): int
    {
        return max(1, (int) (config('subscription.alert_days') ?? env('SUBSCRIPTION_ALERT_DAYS', 7)));
    }

    /**
     * Evaluate the effective subscription state for a branch.
     *
     * Returning an array (not an object) so it can be serialised directly into
     * API responses without an extra transformation step.
     *
     * FAIL-CLOSED POLICY
     * A missing subscription row is a configuration error, not a signal to
     * grant access.  Any branch without an explicit subscription record is
     * treated as NOT_CONFIGURED and locked until the SaaS Owner creates a row.
     *
     * Existing branches were backfilled with explicit 'active'/no-expiry rows by
     * migration 2026_07_15_000003.  New branches receive an explicit row from
     * BranchController::store().  The 'no row = active' fallback has been removed
     * to ensure the system fails closed, not open.
     */
    public function evaluate(int $branchId): array
    {
        $sub = BranchSubscription::where('branch_id', $branchId)->first();

        // No record = subscription not configured.  Access is denied until the
        // SaaS Owner explicitly creates a subscription row.  Use the command
        //   php artisan subscriptions:backfill-branches
        // to create active/no-expiry records for any branches that are missing one.
        if (!$sub) {
            return $this->buildNotConfiguredResult($branchId);
        }

        $now = now(); // respects config('app.timezone')

        // ── Suspended ──────────────────────────────────────────────────────────
        if ($sub->status === 'suspended') {
            $reason = $sub->suspended_reason
                ? 'Branch suspended: ' . $sub->suspended_reason
                : 'This branch has been suspended. Please contact the platform administrator.';
            return $this->buildResult($branchId, 'suspended', true, null, null, false, $reason);
        }

        // ── Admin-explicit expired ────────────────────────────────────────────
        if ($sub->status === 'expired') {
            return $this->buildLockedExpiredResult($branchId, $sub->expires_at);
        }

        // ── Grace period ──────────────────────────────────────────────────────
        if ($sub->status === 'grace_period') {
            $graceCutoff = $sub->grace_until ?? $sub->expires_at;
            if ($graceCutoff && $now->gt(Carbon::parse($graceCutoff))) {
                // Grace window has passed — treat as expired
                return $this->buildLockedExpiredResult($branchId, $graceCutoff);
            }
            $remaining = $this->remainingDays($graceCutoff, $now);
            $msg = $remaining !== null
                ? "Grace period ends in {$remaining} day(s). Renew immediately to avoid losing access."
                : 'In grace period. Please renew immediately.';
            return $this->buildResult($branchId, 'grace_period', false,
                $graceCutoff ? Carbon::parse($graceCutoff) : null, $remaining, true, $msg);
        }

        // ── Trial / Active ────────────────────────────────────────────────────
        // Check date-based expiry for trial and active statuses.
        if ($sub->expires_at && $now->gt(Carbon::parse($sub->expires_at))) {
            return $this->buildLockedExpiredResult($branchId, $sub->expires_at);
        }

        $remaining    = $this->remainingDays($sub->expires_at, $now);
        $threshold    = $this->alertThreshold();
        $showAlert    = $remaining !== null && $remaining <= $threshold;
        $message      = $showAlert
            ? "Subscription expires in {$remaining} day(s). Please contact support to renew."
            : 'Subscription is active.';

        return $this->buildResult(
            $branchId, $sub->status, false,
            $sub->expires_at ? Carbon::parse($sub->expires_at) : null,
            $remaining, $showAlert, $message
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildNotConfiguredResult(int $branchId): array
    {
        return $this->buildResult(
            $branchId,
            'not_configured',
            true,
            null,
            null,
            false,
            'Subscription has not been configured for this branch. Contact the platform administrator.',
        );
    }

    private function buildLockedExpiredResult(int $branchId, mixed $expiresAt): array
    {
        return $this->buildResult(
            $branchId, 'expired', true,
            $expiresAt ? Carbon::parse($expiresAt) : null,
            0, false,
            'This branch subscription has expired. Please contact support to renew.'
        );
    }

    private function buildResult(
        int $branchId,
        string $status,
        bool $isLocked,
        ?Carbon $expiresAt,
        ?int $remainingDays,
        bool $showAlert,
        string $message,
    ): array {
        return [
            'branch_id'      => $branchId,
            'status'         => $status,
            'is_locked'      => $isLocked,
            'expires_at'     => $expiresAt ? $expiresAt->toIso8601String() : null,
            'remaining_days' => $remainingDays,
            'show_alert'     => $showAlert,
            'message'        => $message,
        ];
    }

    /**
     * Returns the number of whole days until $cutoff from $now.
     * Returns null when $cutoff is null (no expiry).
     * Returns 0 when already past (safety floor).
     */
    private function remainingDays(mixed $cutoff, Carbon $now): ?int
    {
        if ($cutoff === null) {
            return null;
        }
        $diff = (int) ceil($now->diffInSeconds(Carbon::parse($cutoff), false) / 86400);
        return max(0, $diff);
    }
}
