<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchFeatureSetting;
use App\Models\BranchSubscription;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Register;
use App\Models\RegisterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Branch Feature Settings — full coverage test suite.
 *
 * ── Group A: Authorization & persistence ─────────────────────────────────────
 *  A1  Master Admin can read any branch features (GET /branches/{id}/features)
 *  A2  Master Admin can update any branch features (PUT /branches/{id}/features)
 *  A3  Normal user can read own branch features (GET /branch-features/current)
 *  A4  Normal user cannot read another branch's features
 *  A5  Normal user cannot update any branch features (403)
 *  A6  Missing settings row resolves to defaults (both enabled)
 *  A7  Branch A changes do not affect Branch B
 *  A8  Audit row records actor, old and new values correctly
 *  A9  No-op save (same values) does not write an audit row
 *  A10 Unknown keys in PUT body are ignored (allowlist enforced)
 *  A11 Non-boolean values for known keys are rejected (422)
 *
 * ── Group B: Delivery disabled enforcement ────────────────────────────────────
 *  B1  Sale without delivery fields succeeds when delivery disabled
 *  B2  Sale with delivery_boy_id fails 422 when delivery disabled
 *  B3  Delivery cash received fails 422 when delivery disabled
 *  B4  Historical sale rows are untouched when delivery is disabled
 *  B5  Disable is blocked (422) when account 1210 has outstanding custody
 *  B6  Disable succeeds when account 1210 balance is zero
 *
 * ── Group C: Sale vendor disabled enforcement ─────────────────────────────────
 *  C1  Sale without vendor_id succeeds when sale_vendor_enabled=false
 *  C2  Sale with vendor_id fails 422 when sale_vendor_enabled=false
 *  C3  Historical vendor-linked sales remain readable after disable
 *
 * ── Group D: Missing row and new branch defaults ──────────────────────────────
 *  D1  GET /branch-features/current for a branch with no settings row returns defaults
 *  D2  GET /branches/{branch}/features for a branch with no settings row returns defaults
 */
class BranchFeatureSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;
    private Branch $branchB;
    private User   $masterAdmin;
    private User   $cashierA;
    private User   $cashierB;
    private Product $productA;

    private const SALE_PERMS = [
        'view-sales', 'create-sales', 'manage-sales',
        'view-register-shifts', 'open-register-shift', 'close-own-register-shift',
    ];

    private const DELIVERY_PERMS = [
        'view-sales', 'create-sales', 'manage-sales',
        'view-delivery-boys', 'manage-delivery-boys',
        'view-register-shifts', 'open-register-shift', 'close-own-register-shift',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // ── Branches ──────────────────────────────────────────────────────────
        $this->branchA = Branch::create(['name' => 'Branch A', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'Branch B', 'is_active' => true]);

        // Subscriptions — withoutMiddleware('branch.subscription') is on the
        // feature routes, but the sale routes need them.
        foreach ([$this->branchA, $this->branchB] as $branch) {
            BranchSubscription::create([
                'branch_id'  => $branch->id,
                'status'     => 'active',
                'expires_at' => now()->addYear(),
            ]);
        }

        // ── Master Admin ──────────────────────────────────────────────────────
        $maRole = Role::firstOrCreate(['name' => 'master_admin', 'guard_name' => 'web']);
        $this->masterAdmin = User::factory()->create(['branch_id' => $this->branchA->id]);
        $this->masterAdmin->assignRole($maRole);

        // ── Cashiers ──────────────────────────────────────────────────────────
        $this->cashierA = User::factory()->create(['branch_id' => $this->branchA->id]);
        $this->givePerms($this->cashierA, self::SALE_PERMS);

        $this->cashierB = User::factory()->create(['branch_id' => $this->branchB->id]);
        $this->givePerms($this->cashierB, self::SALE_PERMS);

        // ── Product & stock for Branch A ──────────────────────────────────────
        $this->productA = Product::create([
            'name'       => 'Widget',
            'branch_id'  => $this->branchA->id,
            'cost_price' => 5.00,
            'price'      => 10.00,
            'sku'        => 'WGT-001',
        ]);
        ProductStock::create([
            'product_id' => $this->productA->id,
            'branch_id'  => $this->branchA->id,
            'quantity'   => 200,
            'avg_cost'   => 5.00,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Group A — Authorization & persistence
    // ─────────────────────────────────────────────────────────────────────────

    /** A1 */
    public function test_master_admin_can_read_any_branch_features(): void
    {
        BranchFeatureSetting::create([
            'branch_id'           => $this->branchB->id,
            'delivery_enabled'    => false,
            'sale_vendor_enabled' => true,
        ]);

        $res = $this->actingAs($this->masterAdmin, 'sanctum')
            ->getJson("/api/v1/branches/{$this->branchB->id}/features");

        $res->assertOk()
            ->assertJsonPath('data.branch_id', $this->branchB->id)
            ->assertJsonPath('data.features.delivery_enabled', false)
            ->assertJsonPath('data.features.sale_vendor_enabled', true);
    }

    /** A2 */
    public function test_master_admin_can_update_any_branch_features(): void
    {
        $res = $this->actingAs($this->masterAdmin, 'sanctum')
            ->putJson("/api/v1/branches/{$this->branchB->id}/features", [
                'delivery_enabled'    => false,
                'sale_vendor_enabled' => false,
            ]);

        $res->assertOk()
            ->assertJsonPath('data.features.delivery_enabled', false)
            ->assertJsonPath('data.features.sale_vendor_enabled', false);

        $this->assertDatabaseHas('branch_feature_settings', [
            'branch_id'           => $this->branchB->id,
            'delivery_enabled'    => false,
            'sale_vendor_enabled' => false,
        ]);
    }

    /** A3 */
    public function test_normal_user_can_read_own_branch_current_features(): void
    {
        BranchFeatureSetting::create([
            'branch_id'           => $this->branchA->id,
            'delivery_enabled'    => true,
            'sale_vendor_enabled' => false,
        ]);

        $res = $this->actingAs($this->cashierA, 'sanctum')
            ->getJson('/api/v1/branch-features/current');

        $res->assertOk()
            ->assertJsonPath('data.branch_id', $this->branchA->id)
            ->assertJsonPath('data.features.sale_vendor_enabled', false);
    }

    /** A4 — normal user cannot use the master-admin route for another branch */
    public function test_normal_user_cannot_read_another_branch_features(): void
    {
        $this->actingAs($this->cashierA, 'sanctum')
            ->getJson("/api/v1/branches/{$this->branchB->id}/features")
            ->assertForbidden();
    }

    /** A5 */
    public function test_normal_user_cannot_update_branch_features(): void
    {
        $this->actingAs($this->cashierA, 'sanctum')
            ->putJson("/api/v1/branches/{$this->branchA->id}/features", [
                'delivery_enabled' => false,
            ])
            ->assertForbidden();
    }

    /** A6 */
    public function test_missing_settings_row_resolves_to_both_enabled(): void
    {
        $this->assertDatabaseMissing('branch_feature_settings', ['branch_id' => $this->branchA->id]);

        $res = $this->actingAs($this->cashierA, 'sanctum')
            ->getJson('/api/v1/branch-features/current');

        $res->assertOk()
            ->assertJsonPath('data.features.delivery_enabled', true)
            ->assertJsonPath('data.features.sale_vendor_enabled', true);
    }

    /** A7 */
    public function test_branch_a_changes_do_not_affect_branch_b(): void
    {
        $this->actingAs($this->masterAdmin, 'sanctum')
            ->putJson("/api/v1/branches/{$this->branchA->id}/features", [
                'delivery_enabled' => false,
            ])
            ->assertOk();

        // Branch B still has no row → defaults (both true).
        $res = $this->actingAs($this->masterAdmin, 'sanctum')
            ->getJson("/api/v1/branches/{$this->branchB->id}/features");

        $res->assertJsonPath('data.features.delivery_enabled', true);
    }

    /** A8 */
    public function test_audit_row_records_actor_old_and_new_values(): void
    {
        // Seed an existing row so we have a known "old" state.
        BranchFeatureSetting::create([
            'branch_id'           => $this->branchA->id,
            'delivery_enabled'    => true,
            'sale_vendor_enabled' => true,
        ]);

        $this->actingAs($this->masterAdmin, 'sanctum')
            ->putJson("/api/v1/branches/{$this->branchA->id}/features", [
                'delivery_enabled' => false,
            ])
            ->assertOk();

        $audit = DB::table('branch_feature_audits')
            ->where('branch_id', $this->branchA->id)
            ->latest('changed_at')
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals($this->masterAdmin->id, $audit->changed_by);

        $old = json_decode($audit->old_values, true);
        $new = json_decode($audit->new_values, true);

        $this->assertTrue($old['delivery_enabled']);
        $this->assertFalse($new['delivery_enabled']);
        // sale_vendor_enabled was not changed.
        $this->assertTrue($old['sale_vendor_enabled']);
        $this->assertTrue($new['sale_vendor_enabled']);
    }

    /** A9 — saving the same values must not create an audit row */
    public function test_no_op_save_does_not_write_audit_row(): void
    {
        BranchFeatureSetting::create([
            'branch_id'           => $this->branchA->id,
            'delivery_enabled'    => true,
            'sale_vendor_enabled' => true,
        ]);

        $before = DB::table('branch_feature_audits')->count();

        $this->actingAs($this->masterAdmin, 'sanctum')
            ->putJson("/api/v1/branches/{$this->branchA->id}/features", [
                'delivery_enabled'    => true,
                'sale_vendor_enabled' => true,
            ])
            ->assertOk();

        $this->assertEquals($before, DB::table('branch_feature_audits')->count());
    }

    /** A10 — unknown keys are silently stripped, not stored */
    public function test_unknown_keys_in_put_body_are_rejected_by_allowlist(): void
    {
        $res = $this->actingAs($this->masterAdmin, 'sanctum')
            ->putJson("/api/v1/branches/{$this->branchA->id}/features", [
                'hack_mode' => true,
            ]);

        // No valid keys → 422.
        $res->assertStatus(422);

        // Ensure no row was created with a non-existent column.
        $this->assertDatabaseMissing('branch_feature_settings', ['branch_id' => $this->branchA->id]);
    }

    /** A11 */
    public function test_non_boolean_values_for_known_keys_are_rejected(): void
    {
        $this->actingAs($this->masterAdmin, 'sanctum')
            ->putJson("/api/v1/branches/{$this->branchA->id}/features", [
                'delivery_enabled' => 'yes_please',
            ])
            ->assertUnprocessable();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Group B — Delivery disabled enforcement
    // ─────────────────────────────────────────────────────────────────────────

    /** B1 — plain sale (no delivery fields) still goes through */
    public function test_sale_without_delivery_succeeds_when_delivery_disabled(): void
    {
        $this->disableDeliveryForBranchA();
        $shift = $this->openShift($this->cashierA, $this->branchA);

        $res = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->minimalSalePayload($shift));

        $res->assertCreated();
    }

    /** B2 */
    public function test_sale_with_delivery_boy_id_fails_when_delivery_disabled(): void
    {
        $this->disableDeliveryForBranchA();
        $shift = $this->openShift($this->cashierA, $this->branchA);
        $deliveryBoy = User::factory()->create(['branch_id' => $this->branchA->id]);

        $res = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', array_merge(
                $this->minimalSalePayload($shift),
                ['delivery_boy_id' => $deliveryBoy->id]
            ));

        $res->assertUnprocessable()
            ->assertJsonPath('errors.delivery.0', fn ($msg) => str_contains($msg, 'disabled'));
    }

    /** B3 — delivery cash-received blocked */
    public function test_delivery_cash_received_fails_when_delivery_disabled(): void
    {
        $this->disableDeliveryForBranchA();
        $this->givePerms($this->cashierA, ['manage-delivery-boys']);

        $deliveryBoy = User::factory()->create(['branch_id' => $this->branchA->id]);

        // Route: POST /api/v1/delivery-boys/{id}/received
        $res = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/delivery-boys/{$deliveryBoy->id}/received", [
                'branch_id' => $this->branchA->id,
                'amount'    => 100,
            ]);

        $res->assertUnprocessable()
            ->assertJsonPath('errors.delivery.0', fn ($msg) => str_contains($msg, 'disabled'));
    }

    /** B4 — existing sale rows are untouched after delivery is disabled */
    public function test_historical_sales_are_not_altered_when_delivery_disabled(): void
    {
        // Create a delivery boy and record his ID directly in a sale row.
        $deliveryBoy = User::factory()->create(['branch_id' => $this->branchA->id]);

        $historicalDeliveryBoyId = $deliveryBoy->id;

        DB::table('sales')->insert([
            'branch_id'       => $this->branchA->id,
            'cashier_id'      => $this->cashierA->id,
            'invoice_no'      => 'HIST-0001',
            'delivery_boy_id' => $historicalDeliveryBoyId,
            'total'           => 50.00,
            'paid'            => 50.00,
            'status'          => 'completed',
            'created_at'      => now()->subDay(),
            'updated_at'      => now()->subDay(),
        ]);

        $this->disableDeliveryForBranchA();

        // The row must still exist with the delivery_boy_id intact.
        $this->assertDatabaseHas('sales', [
            'invoice_no'      => 'HIST-0001',
            'delivery_boy_id' => $historicalDeliveryBoyId,
        ]);
    }

    /** B5 — disable blocked while account 1210 has outstanding balance */
    public function test_delivery_disable_blocked_while_custody_balance_nonzero(): void
    {
        $this->seed1210CustodyBalance($this->branchA, debit: 200.00, credit: 0.00);

        $res = $this->actingAs($this->masterAdmin, 'sanctum')
            ->putJson("/api/v1/branches/{$this->branchA->id}/features", [
                'delivery_enabled' => false,
            ]);

        $res->assertUnprocessable()
            ->assertJsonPath('errors.delivery_enabled.0', fn ($msg) => str_contains($msg, 'custody'));
    }

    /** B6 — disable allowed when 1210 balance is zero */
    public function test_delivery_disable_allowed_when_custody_balance_is_zero(): void
    {
        // Equal debit and credit → net zero.
        $this->seed1210CustodyBalance($this->branchA, debit: 100.00, credit: 100.00);

        $res = $this->actingAs($this->masterAdmin, 'sanctum')
            ->putJson("/api/v1/branches/{$this->branchA->id}/features", [
                'delivery_enabled' => false,
            ]);

        $res->assertOk()
            ->assertJsonPath('data.features.delivery_enabled', false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Group C — Sale vendor disabled enforcement
    // ─────────────────────────────────────────────────────────────────────────

    /** C1 */
    public function test_sale_without_vendor_succeeds_when_vendor_disabled(): void
    {
        $this->disableSaleVendorForBranchA();
        $shift = $this->openShift($this->cashierA, $this->branchA);

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->minimalSalePayload($shift))
            ->assertCreated();
    }

    /** C2 */
    public function test_sale_with_vendor_id_fails_when_vendor_disabled(): void
    {
        $this->disableSaleVendorForBranchA();
        $shift = $this->openShift($this->cashierA, $this->branchA);

        $vendor = DB::table('vendors')->insertGetId([
            'branch_id'  => $this->branchA->id,
            'first_name' => 'Test',
            'last_name'  => 'Vendor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', array_merge(
                $this->minimalSalePayload($shift),
                ['vendor_id' => $vendor]
            ));

        $res->assertUnprocessable()
            ->assertJsonPath('errors.vendor_id.0', fn ($msg) => str_contains($msg, 'disabled'));
    }

    /** C3 — old sales with vendor data remain readable */
    public function test_historical_vendor_linked_sales_remain_readable_after_vendor_disabled(): void
    {
        $vendor = DB::table('vendors')->insertGetId([
            'branch_id'  => $this->branchA->id,
            'first_name' => 'Old',
            'last_name'  => 'Vendor',
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        DB::table('sales')->insert([
            'branch_id'  => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'invoice_no' => 'VEND-HIST-001',
            'vendor_id'  => $vendor,
            'total'      => 80.00,
            'paid'       => 80.00,
            'status'     => 'completed',
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        $this->disableSaleVendorForBranchA();

        // Row untouched.
        $this->assertDatabaseHas('sales', [
            'invoice_no' => 'VEND-HIST-001',
            'vendor_id'  => $vendor,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Group D — Missing row / new branch defaults
    // ─────────────────────────────────────────────────────────────────────────

    /** D1 */
    public function test_current_endpoint_returns_defaults_when_no_row_exists(): void
    {
        $this->assertDatabaseEmpty('branch_feature_settings');

        $res = $this->actingAs($this->cashierA, 'sanctum')
            ->getJson('/api/v1/branch-features/current');

        $res->assertOk()
            ->assertJsonPath('data.features.delivery_enabled', true)
            ->assertJsonPath('data.features.sale_vendor_enabled', true);
    }

    /** D2 */
    public function test_show_endpoint_returns_defaults_for_branch_with_no_row(): void
    {
        // New branch, no feature row.
        $newBranch = Branch::create(['name' => 'New Branch', 'is_active' => true]);

        $res = $this->actingAs($this->masterAdmin, 'sanctum')
            ->getJson("/api/v1/branches/{$newBranch->id}/features");

        $res->assertOk()
            ->assertJsonPath('data.features.delivery_enabled', true)
            ->assertJsonPath('data.features.sale_vendor_enabled', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function givePerms(User $user, array $perms): void
    {
        foreach ($perms as $perm) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($perms);
    }

    private function disableDeliveryForBranchA(): void
    {
        BranchFeatureSetting::updateOrCreate(
            ['branch_id' => $this->branchA->id],
            ['delivery_enabled' => false, 'sale_vendor_enabled' => true]
        );
    }

    private function disableSaleVendorForBranchA(): void
    {
        BranchFeatureSetting::updateOrCreate(
            ['branch_id' => $this->branchA->id],
            ['delivery_enabled' => true, 'sale_vendor_enabled' => false]
        );
    }

    private function openShift(User $cashier, Branch $branch): RegisterShift
    {
        $register = Register::firstOrCreate(
            ['branch_id' => $branch->id, 'code' => 'MAIN'],
            ['name' => 'Main Register']
        );

        return RegisterShift::create([
            'client_ref'   => Str::uuid(),
            'register_id'  => $register->id,
            'branch_id'    => $branch->id,
            'cashier_id'   => $cashier->id,
            'opened_by'    => $cashier->id,
            'status'       => 'open',
            'opened_at'    => now(),
            'opening_cash' => 0,
        ]);
    }

    private function minimalSalePayload(RegisterShift $shift): array
    {
        return [
            'client_ref'               => (string) Str::uuid(),
            'branch_id'                => $shift->branch_id,
            'register_shift_client_ref' => $shift->client_ref,
            'occurred_at'              => now()->toDateTimeString(),
            'items'                    => [
                [
                    'product_id' => $this->productA->id,
                    'quantity'   => 1,
                    'price'      => $this->productA->price,
                    'discount'   => 0,
                ],
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => $this->productA->price],
            ],
            'tax'      => 0,
            'discount' => 0,
        ];
    }

    /**
     * Seed account 1210 journal postings to simulate delivery-boy custody.
     * debit > credit → outstanding balance; equal → cleared.
     */
    private function seed1210CustodyBalance(Branch $branch, float $debit, float $credit): void
    {
        // Ensure account 1210 exists.
        $accountId = DB::table('accounts')->where('code', '1210')->value('id');
        if (!$accountId) {
            $accountId = DB::table('accounts')->insertGetId([
                'code'       => '1210',
                'name'       => 'Delivery Boy Cash In Transit',
                'type'       => 'asset',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // A minimal journal entry for this branch.
        $entryId = DB::table('journal_entries')->insertGetId([
            'branch_id'   => $branch->id,
            'reference'   => 'TEST-CUSTODY',
            'description' => 'Test delivery custody balance',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('journal_postings')->insert([
            'journal_entry_id' => $entryId,
            'account_id'       => $accountId,
            'debit'            => $debit,
            'credit'           => $credit,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }
}
