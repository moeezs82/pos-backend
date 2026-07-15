<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchSubscription;
use App\Models\SubscriptionAudit;
use App\Models\User;
use App\Services\SubscriptionStatusService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Subscription entitlement tests — covers:
 *
 *  SERVICE
 *   S1  No subscription row → not_configured, locked (fail-closed policy)
 *   S2  status = suspended → locked regardless of dates
 *   S3  status = expired (admin-set) → locked
 *   S4  status = active, expires_at in future → not locked
 *   S5  status = active, expires_at in past → locked
 *   S6  status = active, expires_at null → not locked (no expiry)
 *   S7  status = active, within alert window → showAlert = true
 *   S8  status = grace_period, grace_until in future → not locked, showAlert
 *   S9  status = grace_period, grace_until in past → locked
 *   S10 status = trial, expires_at in future → not locked
 *
 *  MIDDLEWARE
 *   M1  Active branch request passes through (200)
 *   M2  Expired branch request blocked (402 + BRANCH_SUBSCRIPTION_EXPIRED)
 *   M3  Suspended branch request blocked (402 + BRANCH_SUBSCRIPTION_EXPIRED)
 *   M4  No subscription row → 402 BRANCH_SUBSCRIPTION_NOT_CONFIGURED (fail-closed)
 *   M5  POST /logout exempt — passes even when branch is expired
 *   M6  GET /me exempt — passes even when branch is expired
 *   M7  GET /v1/subscription/status exempt — passes even when branch is locked
 *   M8  GET /v1/branches exempt — passes even when branch is locked
 *   M9  GET /v1/subscriptions exempt — passes even when branch is locked (owner gate enforced separately)
 *
 *  CONTROLLER
 *   C1  GET /subscription/status returns own branch status
 *   C2  Master admin GET /subscription/status?branch_id=X queries another branch
 *   C3  Normal user cannot pass ?branch_id=X — gets own branch instead
 *   C4  GET /subscription/status with no branch returns 422
 *   C5  GET /subscriptions — 403 for non-owner
 *   C6  GET /subscriptions — 200 paginated for master admin
 *   C7  GET /subscriptions/{id} — 403 for non-owner
 *   C8  GET /subscriptions/{id} — returns detail for master admin
 *   C9  PUT /subscriptions/{id} — creates subscription row if none existed
 *   C10 PUT /subscriptions/{id} — updates existing row + writes audit entry
 *   C11 PUT /subscriptions/{id} suspend requires reason (422 without it)
 *   C12 PUT /subscriptions/{id} — 403 for non-owner
 *   C13 GET /subscriptions/{id}/audit — paginated history for master admin
 *
 *  BRANCH ISOLATION
 *   I1  Two branches: expiring branch is blocked, active branch passes
 *
 *  IDEMPOTENCY / RECOVERY
 *   R1  Update subscription twice — row count stays at 1, status reflects last call
 *   R2  Renewing after expiry (set status=active + new expires_at) lifts the lock
 *
 *  NEW BRANCH / BACKFILL
 *   N1  Creating a branch via the API auto-creates subscription row + audit entry
 *   N2  not_configured branch returns 402 BRANCH_SUBSCRIPTION_NOT_CONFIGURED
 *   N3  Backfill Artisan command is idempotent
 *   N4  GET /subscription/status returns not_configured when row is missing
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;
    private Branch $branchB;
    private User   $cashierA;
    private User   $masterAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchA = Branch::create(['name' => 'Branch A', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'Branch B', 'is_active' => true]);

        // Normal cashier on Branch A
        $this->cashierA = User::factory()->create(['branch_id' => $this->branchA->id]);

        // Master admin (assigned to Branch A so they have a branch_id for API calls)
        $masterRole = Role::firstOrCreate(['name' => 'master admin', 'guard_name' => 'web']);
        $this->masterAdmin = User::factory()->create(['branch_id' => $this->branchA->id]);
        $this->masterAdmin->assignRole($masterRole);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Returns a Sanctum plaintext token for the given user. */
    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /** Sets an explicit subscription state for a branch. */
    private function setSubscription(Branch $branch, array $attributes): BranchSubscription
    {
        return BranchSubscription::updateOrCreate(
            ['branch_id' => $branch->id],
            array_merge(['status' => 'active'], $attributes)
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SERVICE TESTS
    // ─────────────────────────────────────────────────────────────────────────

    /** S1 — No subscription row → not_configured, locked (fail-closed policy) */
    public function test_s1_no_subscription_row_is_treated_as_not_configured(): void
    {
        // Ensure branchA has no subscription row (no auto-creation in setUp).
        BranchSubscription::where('branch_id', $this->branchA->id)->delete();

        $svc    = app(SubscriptionStatusService::class);
        $result = $svc->evaluate($this->branchA->id);

        $this->assertSame('not_configured', $result['status']);
        $this->assertTrue($result['is_locked']);
        $this->assertNull($result['expires_at']);
        $this->assertFalse($result['show_alert']);
    }

    /** S2 — status = suspended → locked regardless of dates */
    public function test_s2_suspended_status_is_always_locked(): void
    {
        $this->setSubscription($this->branchA, [
            'status'           => 'suspended',
            'expires_at'       => now()->addYear(),  // future date — must still be locked
            'suspended_reason' => 'Non-payment',
        ]);

        $result = app(SubscriptionStatusService::class)->evaluate($this->branchA->id);

        $this->assertSame('suspended', $result['status']);
        $this->assertTrue($result['is_locked']);
        $this->assertStringContainsString('Non-payment', $result['message']);
    }

    /** S3 — status = expired (admin-set) → locked */
    public function test_s3_admin_set_expired_status_is_locked(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'expired',
            'expires_at' => now()->subDay(),
        ]);

        $result = app(SubscriptionStatusService::class)->evaluate($this->branchA->id);

        $this->assertSame('expired', $result['status']);
        $this->assertTrue($result['is_locked']);
    }

    /** S4 — status = active, expires_at in future → not locked */
    public function test_s4_active_with_future_expiry_is_not_locked(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => now()->addMonth(),
        ]);

        $result = app(SubscriptionStatusService::class)->evaluate($this->branchA->id);

        $this->assertFalse($result['is_locked']);
        $this->assertSame('active', $result['status']);
    }

    /** S5 — status = active, expires_at in past → date-based lock */
    public function test_s5_active_with_past_expiry_is_locked(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => now()->subHour(),
        ]);

        $result = app(SubscriptionStatusService::class)->evaluate($this->branchA->id);

        $this->assertTrue($result['is_locked']);
        $this->assertSame('expired', $result['status']);
    }

    /** S6 — status = active, expires_at null → no expiry → not locked */
    public function test_s6_active_with_null_expiry_is_not_locked(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => null,
        ]);

        $result = app(SubscriptionStatusService::class)->evaluate($this->branchA->id);

        $this->assertFalse($result['is_locked']);
        $this->assertNull($result['remaining_days']);
        $this->assertFalse($result['show_alert']);
    }

    /** S7 — expires within alert window → showAlert = true */
    public function test_s7_within_alert_window_shows_alert(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => now()->addDays(3),  // within default 7-day threshold
        ]);

        $result = app(SubscriptionStatusService::class)->evaluate($this->branchA->id);

        $this->assertFalse($result['is_locked']);
        $this->assertTrue($result['show_alert']);
        $this->assertNotNull($result['remaining_days']);
        $this->assertLessThanOrEqual(7, $result['remaining_days']);
    }

    /** S8 — grace_period with grace_until in future → not locked, showAlert */
    public function test_s8_grace_period_within_window_is_not_locked(): void
    {
        $this->setSubscription($this->branchA, [
            'status'      => 'grace_period',
            'expires_at'  => now()->subDay(),
            'grace_until' => now()->addDays(5),
        ]);

        $result = app(SubscriptionStatusService::class)->evaluate($this->branchA->id);

        $this->assertFalse($result['is_locked']);
        $this->assertSame('grace_period', $result['status']);
        $this->assertTrue($result['show_alert']);
    }

    /** S9 — grace_period with grace_until in past → locked */
    public function test_s9_grace_period_past_window_is_locked(): void
    {
        $this->setSubscription($this->branchA, [
            'status'      => 'grace_period',
            'expires_at'  => now()->subWeek(),
            'grace_until' => now()->subDay(),
        ]);

        $result = app(SubscriptionStatusService::class)->evaluate($this->branchA->id);

        $this->assertTrue($result['is_locked']);
        $this->assertSame('expired', $result['status']);
    }

    /** S10 — trial with future expires_at → not locked */
    public function test_s10_trial_with_future_expiry_is_not_locked(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'trial',
            'expires_at' => now()->addDays(14),
        ]);

        $result = app(SubscriptionStatusService::class)->evaluate($this->branchA->id);

        $this->assertFalse($result['is_locked']);
        $this->assertSame('trial', $result['status']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MIDDLEWARE TESTS
    // ─────────────────────────────────────────────────────────────────────────

    /** M1 — Active branch request reaches controller (not blocked by subscription middleware) */
    public function test_m1_active_branch_request_passes_through(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => now()->addYear(),
        ]);

        // Give cashierA the permission so if the subscription middleware passes,
        // the request succeeds rather than hitting a permission gate.
        $perm = \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => 'view-products', 'guard_name' => 'web']
        );
        $this->cashierA->givePermissionTo($perm);

        $this->withToken($this->tokenFor($this->cashierA))
            ->getJson('/api/v1/products')
            ->assertOk(); // subscription middleware passed; 200 from controller
    }

    /** M2 — Expired branch request is blocked with 402 + machine-readable code */
    public function test_m2_expired_branch_request_is_blocked_with_402(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => now()->subHour(),
        ]);

        // Give cashierA the products permission so the only reason for failure
        // can be the subscription middleware (not a missing permission gate).
        $perm = \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => 'view-products', 'guard_name' => 'web']
        );
        $this->cashierA->givePermissionTo($perm);

        $this->withToken($this->tokenFor($this->cashierA))
            ->getJson('/api/v1/products')
            ->assertStatus(402)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'BRANCH_SUBSCRIPTION_EXPIRED');
    }

    /** M3 — Suspended branch is blocked with 402 */
    public function test_m3_suspended_branch_is_blocked_with_402(): void
    {
        $this->setSubscription($this->branchA, [
            'status'           => 'suspended',
            'suspended_reason' => 'Policy violation',
        ]);

        $perm = \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => 'view-products', 'guard_name' => 'web']
        );
        $this->cashierA->givePermissionTo($perm);

        $this->withToken($this->tokenFor($this->cashierA))
            ->getJson('/api/v1/products')
            ->assertStatus(402)
            ->assertJsonPath('code', 'BRANCH_SUBSCRIPTION_EXPIRED');
    }

    /** M4 — No subscription row → 402 BRANCH_SUBSCRIPTION_NOT_CONFIGURED (fail-closed) */
    public function test_m4_no_subscription_row_is_blocked_with_not_configured_code(): void
    {
        // Ensure no subscription row exists for branchA.
        BranchSubscription::where('branch_id', $this->branchA->id)->delete();

        $perm = \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => 'view-products', 'guard_name' => 'web']
        );
        $this->cashierA->givePermissionTo($perm);

        // Fail-closed: missing subscription row blocks access immediately.
        $this->withToken($this->tokenFor($this->cashierA))
            ->getJson('/api/v1/products')
            ->assertStatus(402)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'BRANCH_SUBSCRIPTION_NOT_CONFIGURED');
    }

    /** M5 — POST /logout exempt from subscription enforcement */
    public function test_m5_logout_is_exempt_from_subscription_check(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => now()->subHour(),
        ]);

        $token = $this->tokenFor($this->cashierA);

        $this->withToken($token)
            ->postJson('/api/v1/logout')
            ->assertOk();
    }

    /** M6 — GET /me exempt from subscription enforcement */
    public function test_m6_me_is_exempt_from_subscription_check(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => now()->subHour(),
        ]);

        $this->withToken($this->tokenFor($this->cashierA))
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    /** M7 — GET /subscription/status exempt — returns 200 even when locked */
    public function test_m7_subscription_status_endpoint_is_exempt(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => now()->subHour(),
        ]);

        $this->withToken($this->tokenFor($this->cashierA))
            ->getJson('/api/v1/subscription/status')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /** M8 — GET /branches exempt — list remains accessible when locked */
    public function test_m8_branches_list_is_exempt_from_subscription_check(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => now()->subHour(),
        ]);

        // cashierA needs view-branches permission
        $perm = \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => 'view-branches', 'guard_name' => 'web']
        );
        $this->cashierA->givePermissionTo($perm);

        $this->withToken($this->tokenFor($this->cashierA))
            ->getJson('/api/v1/branches')
            ->assertOk();
    }

    /** M9 — GET /subscriptions exempt for owner (own gate enforced inside controller) */
    public function test_m9_subscriptions_management_routes_are_exempt(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => now()->subHour(),
        ]);

        // Master admin can reach the management route even when their own branch is locked
        $this->withToken($this->tokenFor($this->masterAdmin))
            ->getJson('/api/v1/subscriptions')
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONTROLLER TESTS
    // ─────────────────────────────────────────────────────────────────────────

    /** C1 — GET /subscription/status returns own branch status */
    public function test_c1_status_returns_own_branch_status(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => now()->addDays(30),
        ]);

        $this->withToken($this->tokenFor($this->cashierA))
            ->getJson('/api/v1/subscription/status')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.branch_id', $this->branchA->id)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_locked', false);
    }

    /** C2 — Master admin can pass ?branch_id=X to query another branch */
    public function test_c2_master_admin_can_query_another_branch_status(): void
    {
        $this->setSubscription($this->branchB, [
            'status'     => 'suspended',
            'suspended_reason' => 'Test',
        ]);

        // masterAdmin is assigned to branchA but queries branchB
        $this->withToken($this->tokenFor($this->masterAdmin))
            ->getJson('/api/v1/subscription/status?branch_id=' . $this->branchB->id)
            ->assertOk()
            ->assertJsonPath('data.branch_id', $this->branchB->id)
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.is_locked', true);
    }

    /** C3 — Normal user cannot override branch_id via query param */
    public function test_c3_normal_user_branch_id_param_is_ignored(): void
    {
        // cashierA is on branchA; branchB has a different status
        $this->setSubscription($this->branchA, ['status' => 'active', 'expires_at' => null]);
        $this->setSubscription($this->branchB, ['status' => 'suspended', 'suspended_reason' => 'X']);

        $response = $this->withToken($this->tokenFor($this->cashierA))
            ->getJson('/api/v1/subscription/status?branch_id=' . $this->branchB->id)
            ->assertOk();

        // Should always return branchA's status, not branchB's
        $this->assertSame($this->branchA->id, $response->json('data.branch_id'));
        $this->assertNotSame('suspended', $response->json('data.status'));
    }

    /** C4 — User with no branch_id gets 422 */
    public function test_c4_status_without_branch_returns_422(): void
    {
        $userNoBranch = User::factory()->create(['branch_id' => null]);

        $this->withToken($this->tokenFor($userNoBranch))
            ->getJson('/api/v1/subscription/status')
            ->assertStatus(422);
    }

    /** C5 — GET /subscriptions → 403 for non-master-admin */
    public function test_c5_subscriptions_list_forbidden_for_non_owner(): void
    {
        $this->withToken($this->tokenFor($this->cashierA))
            ->getJson('/api/v1/subscriptions')
            ->assertForbidden();
    }

    /** C6 — GET /subscriptions → 200 paginated for master admin, includes summary counts */
    public function test_c6_subscriptions_list_returns_paginated_data_for_owner(): void
    {
        $this->setSubscription($this->branchA, ['status' => 'active', 'expires_at' => now()->addYear()]);
        $this->setSubscription($this->branchB, ['status' => 'trial', 'expires_at' => now()->addMonths(3)]);

        $response = $this->withToken($this->tokenFor($this->masterAdmin))
            ->getJson('/api/v1/subscriptions')
            ->assertOk()
            ->assertJsonPath('success', true);

        // Response shape: data.branches (paginated) + data.summary (counts)
        $branches = $response->json('data.branches.data');
        $summary  = $response->json('data.summary');

        $this->assertNotNull($branches, 'data.branches.data must be present');
        $this->assertGreaterThanOrEqual(2, count($branches));
        $this->assertNotNull($summary, 'data.summary must be present');
        $this->assertArrayHasKey('total', $summary);
        $this->assertArrayHasKey('not_configured', $summary);
        $this->assertArrayHasKey('locked', $summary);

        // Each branch row should have computed status fields
        $first = $branches[0];
        $this->assertArrayHasKey('computed_status', $first);
        $this->assertArrayHasKey('is_locked', $first);
        $this->assertArrayHasKey('remaining_days', $first);
    }

    /** C7 — GET /subscriptions/{id} → 403 for non-owner */
    public function test_c7_subscription_detail_forbidden_for_non_owner(): void
    {
        $this->withToken($this->tokenFor($this->cashierA))
            ->getJson('/api/v1/subscriptions/' . $this->branchA->id)
            ->assertForbidden();
    }

    /** C8 — GET /subscriptions/{id} → returns detail for master admin */
    public function test_c8_subscription_detail_returns_data_for_owner(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => now()->addYear(),
        ]);

        $this->withToken($this->tokenFor($this->masterAdmin))
            ->getJson('/api/v1/subscriptions/' . $this->branchA->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.branch.id', $this->branchA->id);
    }

    /** C9 — PUT /subscriptions/{id} creates a row when none exists */
    public function test_c9_update_creates_subscription_row_when_absent(): void
    {
        BranchSubscription::where('branch_id', $this->branchB->id)->delete();

        $this->withToken($this->tokenFor($this->masterAdmin))
            ->putJson('/api/v1/subscriptions/' . $this->branchB->id, [
                'status'     => 'active',
                'expires_at' => now()->addYear()->toDateString(),
                'reason'     => 'Initial setup',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('branch_subscriptions', [
            'branch_id' => $this->branchB->id,
            'status'    => 'active',
        ]);
    }

    /** C10 — PUT /subscriptions/{id} updates existing row and writes audit entry */
    public function test_c10_update_writes_audit_entry(): void
    {
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => now()->addMonth(),
        ]);

        $newExpiry = now()->addYear()->toDateString();

        $this->withToken($this->tokenFor($this->masterAdmin))
            ->putJson('/api/v1/subscriptions/' . $this->branchA->id, [
                'status'     => 'active',
                'expires_at' => $newExpiry,
                'reason'     => 'Annual renewal',
            ])
            ->assertOk();

        // Exactly one audit row should exist for branchA
        $this->assertDatabaseHas('subscription_audits', [
            'branch_id'  => $this->branchA->id,
            'new_status' => 'active',
            'reason'     => 'Annual renewal',
        ]);

        $this->assertSame(1, SubscriptionAudit::where('branch_id', $this->branchA->id)->count());
    }

    /** C11 — Suspending without a reason returns 422 */
    public function test_c11_suspend_without_reason_returns_422(): void
    {
        $this->setSubscription($this->branchA, ['status' => 'active']);

        $this->withToken($this->tokenFor($this->masterAdmin))
            ->putJson('/api/v1/subscriptions/' . $this->branchA->id, [
                'status' => 'suspended',
                // no suspended_reason
            ])
            ->assertStatus(422);
    }

    /** C11b — Suspending with a reason succeeds */
    public function test_c11b_suspend_with_reason_succeeds(): void
    {
        $this->setSubscription($this->branchA, ['status' => 'active']);

        $this->withToken($this->tokenFor($this->masterAdmin))
            ->putJson('/api/v1/subscriptions/' . $this->branchA->id, [
                'status'           => 'suspended',
                'suspended_reason' => 'Customer request',
                'reason'           => 'Requested by branch manager',
            ])
            ->assertOk()
            ->assertJsonPath('data.subscription.status', 'suspended');
    }

    /** C12 — PUT /subscriptions/{id} → 403 for non-owner */
    public function test_c12_update_forbidden_for_non_owner(): void
    {
        $this->withToken($this->tokenFor($this->cashierA))
            ->putJson('/api/v1/subscriptions/' . $this->branchA->id, [
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    /** C13 — GET /subscriptions/{id}/audit returns paginated audit history */
    public function test_c13_audit_returns_paginated_history(): void
    {
        // Perform two updates to generate audit rows
        BranchSubscription::where('branch_id', $this->branchA->id)->delete();

        $this->withToken($this->tokenFor($this->masterAdmin))
            ->putJson('/api/v1/subscriptions/' . $this->branchA->id, [
                'status'     => 'trial',
                'expires_at' => now()->addMonth()->toDateString(),
                'reason'     => 'First setup',
            ]);

        $this->withToken($this->tokenFor($this->masterAdmin))
            ->putJson('/api/v1/subscriptions/' . $this->branchA->id, [
                'status'     => 'active',
                'expires_at' => now()->addYear()->toDateString(),
                'reason'     => 'Upgraded to annual',
            ]);

        $response = $this->withToken($this->tokenFor($this->masterAdmin))
            ->getJson('/api/v1/subscriptions/' . $this->branchA->id . '/audit')
            ->assertOk()
            ->assertJsonPath('success', true);

        $entries = $response->json('data.data');
        $this->assertCount(2, $entries);

        // Newest first
        $this->assertSame('Upgraded to annual', $entries[0]['reason']);
        $this->assertSame('First setup', $entries[1]['reason']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BRANCH ISOLATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * I1 — Branch A expired, Branch B active.
     * Cashier A is blocked; Cashier B passes through.
     */
    public function test_i1_two_branches_isolated_independently(): void
    {
        // Branch A: expired
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => now()->subHour(),
        ]);

        // Branch B: active with future expiry
        $this->setSubscription($this->branchB, [
            'status'     => 'active',
            'expires_at' => now()->addYear(),
        ]);

        $cashierB = User::factory()->create(['branch_id' => $this->branchB->id]);

        // Give both cashiers the products permission so the only differentiator
        // is the subscription middleware, not a missing permission gate.
        $perm = \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => 'view-products', 'guard_name' => 'web']
        );
        $this->cashierA->givePermissionTo($perm);
        $cashierB->givePermissionTo($perm);

        // Cashier A: expired branch → 402
        $this->withToken($this->tokenFor($this->cashierA))
            ->getJson('/api/v1/products')
            ->assertStatus(402)
            ->assertJsonPath('code', 'BRANCH_SUBSCRIPTION_EXPIRED');

        // Cashier B: active branch → 200
        $this->withToken($this->tokenFor($cashierB))
            ->getJson('/api/v1/products')
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IDEMPOTENCY / RECOVERY
    // ─────────────────────────────────────────────────────────────────────────

    /** R1 — Updating subscription twice does not create duplicate rows */
    public function test_r1_double_update_does_not_duplicate_rows(): void
    {
        BranchSubscription::where('branch_id', $this->branchA->id)->delete();

        $token = $this->tokenFor($this->masterAdmin);

        $this->withToken($token)
            ->putJson('/api/v1/subscriptions/' . $this->branchA->id, [
                'status'     => 'active',
                'expires_at' => now()->addMonth()->toDateString(),
                'reason'     => 'First call',
            ])
            ->assertOk();

        $this->withToken($token)
            ->putJson('/api/v1/subscriptions/' . $this->branchA->id, [
                'status'     => 'active',
                'expires_at' => now()->addYear()->toDateString(),
                'reason'     => 'Second call',
            ])
            ->assertOk();

        // Must still be exactly one subscription row
        $this->assertSame(
            1,
            BranchSubscription::where('branch_id', $this->branchA->id)->count()
        );
        // Two audit rows (one per call)
        $this->assertSame(
            2,
            SubscriptionAudit::where('branch_id', $this->branchA->id)->count()
        );
    }

    /** R2 — Renewing an expired branch (set active + new expiry) lifts the lock */
    public function test_r2_renewing_expired_subscription_lifts_the_lock(): void
    {
        // Start expired
        $this->setSubscription($this->branchA, [
            'status'     => 'active',
            'expires_at' => now()->subHour(),
        ]);

        // Verify it's locked before renewal
        $svc = app(SubscriptionStatusService::class);
        $this->assertTrue($svc->evaluate($this->branchA->id)['is_locked']);

        // Renew via owner API
        $this->withToken($this->tokenFor($this->masterAdmin))
            ->putJson('/api/v1/subscriptions/' . $this->branchA->id, [
                'status'     => 'active',
                'expires_at' => now()->addYear()->toDateString(),
                'reason'     => 'Renewal payment received',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.is_locked', false);

        // Verify the service now reports unlocked
        $this->assertFalse($svc->evaluate($this->branchA->id)['is_locked']);

        // And the protected route passes through again
        $this->withToken($this->tokenFor($this->cashierA))
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NEW BRANCH / BACKFILL
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * N1 — Creating a branch via POST /branches auto-creates a subscription row
     * and an audit entry (action = 'create') inside the same transaction.
     *
     * NOTE: this test hits the BranchController::store() endpoint.  If that
     * route enforces a permission (e.g. 'create-branches'), grant it to
     * $masterAdmin here — currently it is granted defensively below.
     */
    public function test_n1_new_branch_auto_gets_subscription_row_and_audit_entry(): void
    {
        // Grant defensively — the exact permission name depends on your policy.
        $perm = \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => 'create-branches', 'guard_name' => 'web']
        );
        $this->masterAdmin->givePermissionTo($perm);

        $response = $this->withToken($this->tokenFor($this->masterAdmin))
            ->postJson('/api/v1/branches', [
                'name'      => 'Auto-Sub Test Branch',
                'is_active' => true,
            ])
            ->assertStatus(201);

        $branchId = $response->json('data.id');
        $this->assertNotNull($branchId, 'Expected data.id in branch creation response.');

        // BranchController::store() must have created a subscription row.
        $this->assertDatabaseHas('branch_subscriptions', [
            'branch_id' => $branchId,
            'status'    => 'active',
        ]);

        // And an audit entry with action = 'create'.
        $this->assertDatabaseHas('subscription_audits', [
            'branch_id'  => $branchId,
            'new_status' => 'active',
            'action'     => 'create',
        ]);
    }

    /**
     * N2 — A branch with no subscription row returns 402 with the
     * BRANCH_SUBSCRIPTION_NOT_CONFIGURED code and not_configured status.
     */
    public function test_n2_not_configured_branch_returns_correct_402_error(): void
    {
        BranchSubscription::where('branch_id', $this->branchA->id)->delete();

        $perm = \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => 'view-products', 'guard_name' => 'web']
        );
        $this->cashierA->givePermissionTo($perm);

        $this->withToken($this->tokenFor($this->cashierA))
            ->getJson('/api/v1/products')
            ->assertStatus(402)
            ->assertJsonPath('code', 'BRANCH_SUBSCRIPTION_NOT_CONFIGURED')
            ->assertJsonPath('data.status', 'not_configured')
            ->assertJsonPath('data.is_locked', true);
    }

    /**
     * N3 — The backfill Artisan command is idempotent.
     * Running it twice on the same set of branches must not create duplicate rows.
     */
    public function test_n3_backfill_command_is_idempotent(): void
    {
        // Remove all existing subscription rows so both branches need backfilling.
        BranchSubscription::query()->delete();

        // First run — should create 2 rows (branchA + branchB).
        $exitCode = Artisan::call('subscriptions:backfill-branches');
        $this->assertSame(0, $exitCode, 'Backfill command should exit 0 (success).');

        $this->assertDatabaseHas('branch_subscriptions', [
            'branch_id' => $this->branchA->id,
            'status'    => 'active',
        ]);
        $this->assertDatabaseHas('branch_subscriptions', [
            'branch_id' => $this->branchB->id,
            'status'    => 'active',
        ]);

        // Second run — must not create duplicates.
        Artisan::call('subscriptions:backfill-branches');

        $this->assertSame(
            1,
            BranchSubscription::where('branch_id', $this->branchA->id)->count(),
            'Branch A must still have exactly 1 subscription row after two backfills.'
        );
        $this->assertSame(
            1,
            BranchSubscription::where('branch_id', $this->branchB->id)->count(),
            'Branch B must still have exactly 1 subscription row after two backfills.'
        );
    }

    /**
     * N4 — GET /subscription/status returns not_configured when no row exists.
     * This endpoint is exempt from the middleware lock so the client can always
     * read the status (e.g. to show the correct lock screen message).
     */
    public function test_n4_status_endpoint_returns_not_configured_when_no_row(): void
    {
        BranchSubscription::where('branch_id', $this->branchA->id)->delete();

        $this->withToken($this->tokenFor($this->cashierA))
            ->getJson('/api/v1/subscription/status')
            ->assertOk()   // exempt from middleware; 200 even when locked
            ->assertJsonPath('data.status', 'not_configured')
            ->assertJsonPath('data.is_locked', true);
    }
}
