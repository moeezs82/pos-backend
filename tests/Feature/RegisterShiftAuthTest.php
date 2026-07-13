<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Register;
use App\Models\RegisterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Register-shift authentication and token-isolation tests.
 *
 * These tests cover the exact multi-user desktop scenario that exposed the
 * auth bugs:
 *
 *  A) Backend: logout only revokes the current token, not every token the user
 *     owns — so User B's session is not invalidated when User A logs out.
 *
 *  B) Backend: GET /register-shifts/active is scoped to the requesting user,
 *     so User B never sees User A's shift and User A's shift is restored
 *     correctly after a re-login (no app restart required).
 *
 *  C) Backend: two different cashiers can hold open shifts on two different
 *     registers simultaneously — the "only one open shift per cashier" guard
 *     is per-cashier, not global.
 *
 *  D) Backend: the same cashier cannot open a second shift while one is still
 *     open (the existing concurrency guard is preserved).
 *
 *  E) Backend: an expired / invalid token returns 401 — not a silent "no shift"
 *     response that would mask real auth failures on the client.
 */
class RegisterShiftAuthTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Register $reg1;
    private Register $reg2;
    private const SHIFT_PERMS = [
        'view-register-shifts',
        'open-register-shift',
        'close-own-register-shift',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Main Branch', 'is_active' => true]);

        foreach (self::SHIFT_PERMS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        Permission::firstOrCreate(['name' => 'manage-register-shifts', 'guard_name' => 'web']);

        $this->reg1 = Register::create([
            'branch_id' => $this->branch->id,
            'name'      => 'Register 1',
            'code'      => 'REG1',
            'is_active' => true,
        ]);

        $this->reg2 = Register::create([
            'branch_id' => $this->branch->id,
            'name'      => 'Register 2',
            'code'      => 'REG2',
            'is_active' => true,
        ]);
    }

    // -----------------------------------------------------------------------
    // A. Token isolation on logout
    // -----------------------------------------------------------------------

    /**
     * @test
     * Logout must only delete the token used for that request.
     *
     * Bug: the original implementation called `tokens()->delete()` which wipes
     * EVERY token the user has ever created.  If User B happened to share an
     * account (different device, different session) — or, more relevantly, if
     * User A's logout was still being processed when User B's token was issued
     * — User B's token would be silently revoked.
     *
     * Fix: `currentAccessToken()->delete()` removes only the one token that
     * authenticated the logout request.
     */
    public function test_logout_revokes_only_the_current_token(): void
    {
        $userA = $this->cashier('cashier-a@test.com');

        // Issue two separate tokens for the same user (simulates two devices /
        // two concurrent sessions — the minimal case for the multi-token scenario).
        $tokenA1 = $userA->createToken('device-1')->plainTextToken;
        $tokenA2 = $userA->createToken('device-2')->plainTextToken;

        // Log out with the first token.
        $this->withToken($tokenA1)
            ->postJson('/api/v1/logout')
            ->assertOk();

        // The second token must still work.
        $this->withToken($tokenA2)
            ->getJson('/api/v1/register-shifts/active')
            ->assertOk();
    }

    /**
     * @test
     * When User A logs out, User B's independently-issued token stays valid.
     *
     * This is the exact desktop scenario:
     *   1. User A logs in → gets tokenA
     *   2. User B logs in → gets tokenB (different user, different token)
     *   3. User A logs out using tokenA
     *   4. User B's tokenB must still be accepted
     */
    public function test_user_a_logout_does_not_invalidate_user_b_token(): void
    {
        $userA = $this->cashier('cashier-a@test.com');
        $userB = $this->cashier('cashier-b@test.com');

        $tokenA = $userA->createToken('pos-token')->plainTextToken;
        $tokenB = $userB->createToken('pos-token')->plainTextToken;

        // User A logs out.
        $this->withToken($tokenA)
            ->postJson('/api/v1/logout')
            ->assertOk();

        // User B's independent token must still work — this is the core of the
        // multi-user "Unauthenticated" bug.
        $this->withToken($tokenB)
            ->getJson('/api/v1/register-shifts/active')
            ->assertOk()
            ->assertJsonPath('data.shift', null);
    }

    // -----------------------------------------------------------------------
    // B. GET /register-shifts/active is user-scoped
    // -----------------------------------------------------------------------

    /**
     * @test
     * The active-shift endpoint returns only the authenticated user's shift.
     *
     * If User A has an open shift, User B calling /active must receive
     * shift=null (not User A's shift) — they have different cashier IDs.
     * Conversely, User A must be able to re-fetch their own shift with a new
     * token without restarting the app (the server restores it correctly).
     */
    public function test_active_shift_endpoint_is_scoped_to_authenticated_user(): void
    {
        $userA = $this->cashier('cashier-a@test.com');
        $userB = $this->cashier('cashier-b@test.com');

        $tokenA = $userA->createToken('pos-token')->plainTextToken;
        $tokenB = $userB->createToken('pos-token')->plainTextToken;

        // Open a shift for User A.
        $this->withToken($tokenA)
            ->postJson('/api/v1/register-shifts/open', $this->openPayload($this->reg1->id))
            ->assertCreated();

        // User B: should NOT see User A's shift.
        $this->withToken($tokenB)
            ->getJson('/api/v1/register-shifts/active')
            ->assertOk()
            ->assertJsonPath('data.shift', null);

        // User A re-fetches (simulates re-login without app restart): must
        // recover their existing shift.
        $this->withToken($tokenA)
            ->getJson('/api/v1/register-shifts/active')
            ->assertOk()
            ->assertJsonPath('data.shift.register_id', $this->reg1->id)
            ->assertJsonPath('data.shift.status', 'open');
    }

    /**
     * @test
     * After User A logs out and logs back in with a new token, their open shift
     * is still returned by /active — the shift is not destroyed by the logout.
     */
    public function test_active_shift_survives_logout_and_relogin(): void
    {
        $userA = $this->cashier('cashier-a@test.com');

        $tokenA1 = $userA->createToken('pos-token')->plainTextToken;

        $this->withToken($tokenA1)
            ->postJson('/api/v1/register-shifts/open', $this->openPayload($this->reg1->id))
            ->assertCreated();

        // Logout with the first token.
        $this->withToken($tokenA1)
            ->postJson('/api/v1/logout')
            ->assertOk();

        // Simulate re-login: issue a brand-new token (as login() does).
        $tokenA2 = $userA->createToken('pos-token')->plainTextToken;

        // The shift must still be there.
        $this->withToken($tokenA2)
            ->getJson('/api/v1/register-shifts/active')
            ->assertOk()
            ->assertJsonPath('data.shift.status', 'open')
            ->assertJsonPath('data.shift.register_id', $this->reg1->id);
    }

    // -----------------------------------------------------------------------
    // C. Two different cashiers can hold open shifts simultaneously
    // -----------------------------------------------------------------------

    /**
     * @test
     * The "one open shift per cashier" guard is per-cashier, not global.
     * User A can have shift on Register 1 while User B opens Register 2.
     * This is the multi-user scenario: the second cashier must not get a
     * "register already has an open shift" error for a different register.
     */
    public function test_two_cashiers_can_hold_open_shifts_on_different_registers(): void
    {
        $userA = $this->cashier('cashier-a@test.com');
        $userB = $this->cashier('cashier-b@test.com');

        $tokenA = $userA->createToken('pos-token')->plainTextToken;
        $tokenB = $userB->createToken('pos-token')->plainTextToken;

        $this->withToken($tokenA)
            ->postJson('/api/v1/register-shifts/open', $this->openPayload($this->reg1->id))
            ->assertCreated();

        $this->withToken($tokenB)
            ->postJson('/api/v1/register-shifts/open', $this->openPayload($this->reg2->id))
            ->assertCreated();

        $this->assertDatabaseCount('register_shifts', 2);
        $this->assertDatabaseHas('register_shifts', ['cashier_id' => $userA->id, 'register_id' => $this->reg1->id, 'status' => 'open']);
        $this->assertDatabaseHas('register_shifts', ['cashier_id' => $userB->id, 'register_id' => $this->reg2->id, 'status' => 'open']);
    }

    /**
     * @test
     * The occupied-shifts list returned with /active when the requesting user
     * has no shift must exclude registers held by other cashiers.  This drives
     * the UI rule: "only show registers that are NOT already occupied."
     */
    public function test_active_endpoint_lists_occupied_registers_when_user_has_no_shift(): void
    {
        $userA = $this->cashier('cashier-a@test.com');
        $userB = $this->cashier('cashier-b@test.com');

        $tokenA = $userA->createToken('pos-token')->plainTextToken;
        $tokenB = $userB->createToken('pos-token')->plainTextToken;

        // Open reg1 for User A.
        $this->withToken($tokenA)
            ->postJson('/api/v1/register-shifts/open', $this->openPayload($this->reg1->id))
            ->assertCreated();

        // User B has no shift → should see reg1 in the occupied list.
        $response = $this->withToken($tokenB)
            ->getJson('/api/v1/register-shifts/active')
            ->assertOk()
            ->assertJsonPath('data.shift', null);

        $occupied = collect($response->json('data.occupied_shifts'));
        $this->assertTrue(
            $occupied->contains(fn ($s) => (int) $s['register_id'] === $this->reg1->id),
            'Register 1 (held by User A) should appear in the occupied list seen by User B',
        );
    }

    // -----------------------------------------------------------------------
    // D. Concurrency: a cashier cannot open a second shift
    // -----------------------------------------------------------------------

    /**
     * @test
     * A cashier who already has an open shift cannot open another one.
     * This guard must survive the fix — we are only removing the global-token-
     * wipe bug, not the per-cashier uniqueness constraint.
     */
    public function test_cashier_cannot_open_second_shift_while_first_is_open(): void
    {
        $userA = $this->cashier('cashier-a@test.com');
        $tokenA = $userA->createToken('pos-token')->plainTextToken;

        $this->withToken($tokenA)
            ->postJson('/api/v1/register-shifts/open', $this->openPayload($this->reg1->id))
            ->assertCreated();

        $this->withToken($tokenA)
            ->postJson('/api/v1/register-shifts/open', $this->openPayload($this->reg2->id))
            ->assertUnprocessable()
            ->assertJsonPath('errors.cashier.0', 'You already have an open register shift.');
    }

    /**
     * @test
     * A register that already has an open shift cannot be opened by a second
     * cashier (even though they are different users).
     */
    public function test_register_cannot_be_opened_by_two_cashiers(): void
    {
        $userA = $this->cashier('cashier-a@test.com');
        $userB = $this->cashier('cashier-b@test.com');

        $tokenA = $userA->createToken('pos-token')->plainTextToken;
        $tokenB = $userB->createToken('pos-token')->plainTextToken;

        $this->withToken($tokenA)
            ->postJson('/api/v1/register-shifts/open', $this->openPayload($this->reg1->id))
            ->assertCreated();

        // User B tries the same register.
        $this->withToken($tokenB)
            ->postJson('/api/v1/register-shifts/open', $this->openPayload($this->reg1->id))
            ->assertUnprocessable()
            ->assertJsonPath('errors.register_id.0', 'This register already has an open shift.');
    }

    // -----------------------------------------------------------------------
    // E. Invalid token returns 401 — not a silent "no shift"
    // -----------------------------------------------------------------------

    /**
     * @test
     * A bogus / revoked token must return 401.  The Flutter client must treat
     * this as an authentication error, not as "no active shift."  A silent
     * null response would hide the real cause and leave the shift state corrupt.
     */
    public function test_invalid_token_returns_401_on_active_endpoint(): void
    {
        $this->withToken('invalid-token-that-does-not-exist')
            ->getJson('/api/v1/register-shifts/active')
            ->assertUnauthorized();
    }

    /**
     * @test
     * After a token is explicitly revoked (logout), subsequent calls with that
     * same token are rejected with 401.
     */
    public function test_revoked_token_returns_401(): void
    {
        $userA = $this->cashier('cashier-a@test.com');
        $tokenA = $userA->createToken('pos-token')->plainTextToken;

        $this->withToken($tokenA)->postJson('/api/v1/logout')->assertOk();

        $this->withToken($tokenA)
            ->getJson('/api/v1/register-shifts/active')
            ->assertUnauthorized();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function cashier(string $email): User
    {
        $user = User::factory()->create([
            'email'     => $email,
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $user->givePermissionTo(self::SHIFT_PERMS);
        return $user;
    }

    private function openPayload(int $registerId): array
    {
        return [
            'register_id'  => $registerId,
            'client_ref'   => (string) Str::uuid(),
            'opening_cash' => 500.00,
            'opened_at'    => now()->toIso8601String(),
        ];
    }
}
