<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchSubscription;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Register;
use App\Models\RegisterShift;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Offline invoice-number collision tests.
 *
 * Covers the production bug where a queued sale's retry would get
 * "UNIQUE constraint failed: sales.offline_invoice_no" and loop
 * forever as a retryable 500 error.
 *
 * ── Group A: Happy paths ─────────────────────────────────────────────────────
 *  A1  Online sale (no offline_invoice_no) — invoice_no allocated correctly
 *  A2  Sale with offline_invoice_no — field stored and returned in response
 *  A3  Synced offline sale: occurred_at drives invoice date, not sync time
 *
 * ── Group B: Idempotent replay ───────────────────────────────────────────────
 *  B1  Same client_ref twice — pre-check catches it, already_existed=true, 1 row
 *  B2  client_ref race (constraint path) — constraint-level catch returns 200
 *  B3  Same client_ref + same offline_invoice_no — client_ref match wins (200)
 *
 * ── Group C: offline_invoice_no collision (the core bug) ─────────────────────
 *  C1  Different client_ref + same offline_invoice_no → 409 OFFLINE_INVOICE_NO_COLLISION
 *  C2  409 body has code, offline_invoice_no, conflicting_invoice_no
 *  C3  409 does NOT create a second sale (DB count remains 1)
 *  C4  Multiple NULL offline_invoice_nos allowed (UNIQUE does not constrain NULLs)
 *  C5  Different branch encoded in the offline ref string — no collision possible
 *
 * ── Group D: verify-batch reconciliation ─────────────────────────────────────
 *  D1  verify-batch: found entry includes invoice_no and offline_invoice_no
 *  D2  verify-batch: unknown client_ref listed in missing[]
 *  D3  verify-batch: collision victim's client_ref (not in DB) returns missing
 *
 * ── Group E: Search & branch isolation ───────────────────────────────────────
 *  E1  Search by offline_invoice_no finds the correct sale
 *  E2  Search by offline_invoice_no is branch-scoped
 */
class OfflineInvoiceCollisionTest extends TestCase
{
    use RefreshDatabase;

    private Branch        $branchA;
    private Branch        $branchB;
    private User          $cashierA;
    private User          $cashierB;
    private RegisterShift $shiftA;
    private RegisterShift $shiftB;
    private Product       $productA;
    private Product       $productB;

    private const PERMS = [
        'view-sales', 'create-sales',
        'view-register-shifts', 'open-register-shift', 'close-own-register-shift',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::PERMS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        // ── Branches ──────────────────────────────────────────────────────────
        $this->branchA = Branch::create(['name' => 'Branch A', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'Branch B', 'is_active' => true]);

        // Active subscriptions for both branches so the subscription middleware
        // does not block the sale API calls under test.
        BranchSubscription::create([
            'branch_id'  => $this->branchA->id,
            'status'     => 'active',
            'expires_at' => now()->addYear(),
        ]);
        BranchSubscription::create([
            'branch_id'  => $this->branchB->id,
            'status'     => 'active',
            'expires_at' => now()->addYear(),
        ]);

        // ── Users ─────────────────────────────────────────────────────────────
        $this->cashierA = User::factory()->create(['branch_id' => $this->branchA->id]);
        $this->cashierA->givePermissionTo(self::PERMS);

        $this->cashierB = User::factory()->create(['branch_id' => $this->branchB->id]);
        $this->cashierB->givePermissionTo(self::PERMS);

        // ── Registers & open shifts ───────────────────────────────────────────
        $regA = Register::create([
            'branch_id' => $this->branchA->id,
            'name'      => 'Main Register',
            'code'      => 'MAIN',
        ]);
        $regB = Register::create([
            'branch_id' => $this->branchB->id,
            'name'      => 'Main Register',
            'code'      => 'MAIN',
        ]);

        $now = now();
        $this->shiftA = RegisterShift::create([
            'client_ref'   => Str::uuid(),
            'register_id'  => $regA->id,
            'branch_id'    => $this->branchA->id,
            'cashier_id'   => $this->cashierA->id,
            'opened_by'    => $this->cashierA->id,
            'status'       => 'open',
            'opened_at'    => $now,
            'opening_cash' => 0,
        ]);
        $this->shiftB = RegisterShift::create([
            'client_ref'   => Str::uuid(),
            'register_id'  => $regB->id,
            'branch_id'    => $this->branchB->id,
            'cashier_id'   => $this->cashierB->id,
            'opened_by'    => $this->cashierB->id,
            'status'       => 'open',
            'opened_at'    => $now,
            'opening_cash' => 0,
        ]);

        // ── Products & stock ──────────────────────────────────────────────────
        $this->productA = Product::create([
            'name'       => 'Item A',
            'branch_id'  => $this->branchA->id,
            'cost_price' => 10.00,
            'price'      => 20.00,
            'sku'        => 'COLA-001',
        ]);
        ProductStock::create([
            'product_id' => $this->productA->id,
            'branch_id'  => $this->branchA->id,
            'quantity'   => 100,
            'avg_cost'   => 10.00,
        ]);

        $this->productB = Product::create([
            'name'       => 'Item B',
            'branch_id'  => $this->branchB->id,
            'cost_price' => 10.00,
            'price'      => 20.00,
            'sku'        => 'COLA-002',
        ]);
        ProductStock::create([
            'product_id' => $this->productB->id,
            'branch_id'  => $this->branchB->id,
            'quantity'   => 100,
            'avg_cost'   => 10.00,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function payloadA(array $override = []): array
    {
        return array_merge([
            'items'                    => [['product_id' => $this->productA->id, 'quantity' => 1, 'price' => 20]],
            'discount'                 => 0,
            'tax'                      => 0,
            'payments'                 => [['amount' => 20, 'method' => 'cash']],
            'client_ref'               => (string) Str::uuid(),
            'register_shift_client_ref' => $this->shiftA->client_ref,
        ], $override);
    }

    private function payloadB(array $override = []): array
    {
        return array_merge([
            'items'                    => [['product_id' => $this->productB->id, 'quantity' => 1, 'price' => 20]],
            'discount'                 => 0,
            'tax'                      => 0,
            'payments'                 => [['amount' => 20, 'method' => 'cash']],
            'client_ref'               => (string) Str::uuid(),
            'register_shift_client_ref' => $this->shiftB->client_ref,
        ], $override);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Group A: Happy paths
    // ═════════════════════════════════════════════════════════════════════════

    /** @test A1 */
    public function online_sale_without_offline_invoice_no_receives_invoice_number(): void
    {
        $res = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadA())
            ->assertOk()
            ->json('data.sale');

        $dateStr = now()->format('Ymd');
        $this->assertStringStartsWith("INV-{$dateStr}-", $res['invoice_no']);
        $this->assertNull($res['offline_invoice_no']);
        $this->assertDatabaseCount('sales', 1);
    }

    /** @test A2 */
    public function sale_with_offline_invoice_no_stores_and_returns_both_references(): void
    {
        $offlineRef = 'OFF-B1-MAIN-20260715-0001';

        $data = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadA([
                'offline_invoice_no' => $offlineRef,
                'occurred_at'        => '2026-07-15 10:00:00',
            ]))
            ->assertOk()
            ->json('data');

        // Both references must be present in the response and the database.
        $this->assertSame($offlineRef, $data['offline_invoice_no']);
        $this->assertStringStartsWith('INV-20260715-', $data['invoice_no']);
        $this->assertDatabaseHas('sales', ['offline_invoice_no' => $offlineRef]);
    }

    /** @test A3 */
    public function offline_synced_sale_uses_occurred_at_date_not_sync_date(): void
    {
        // The cashier created this sale on 2026-07-10, but it's syncing today.
        $occurredAt = '2026-07-10 14:30:00';

        $res = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadA([
                'offline_invoice_no' => 'OFF-B1-MAIN-20260710-0001',
                'occurred_at'        => $occurredAt,
            ]))
            ->assertOk()
            ->json('data.sale');

        // The invoice number carries the original sale date, not today's date.
        $this->assertStringContainsString('INV-20260710-', $res['invoice_no']);
        // The invoice_date column must also reflect the original date.
        $this->assertDatabaseHas('sales', [
            'invoice_no'   => $res['invoice_no'],
            'invoice_date' => '2026-07-10',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Group B: Idempotent replay
    // ═════════════════════════════════════════════════════════════════════════

    /** @test B1 */
    public function same_client_ref_submitted_twice_returns_already_existed_via_pre_check(): void
    {
        $clientRef  = (string) Str::uuid();
        $offlineRef = 'OFF-B1-MAIN-20260715-0010';
        $payload    = $this->payloadA([
            'client_ref'         => $clientRef,
            'offline_invoice_no' => $offlineRef,
        ]);

        // First POST creates the sale.
        $first = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $payload)
            ->assertOk()
            ->json('data');

        // Second POST with the identical payload triggers the pre-check (no DB
        // roundtrip to the transaction needed).
        $second = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $payload)
            ->assertOk()
            ->json('data');

        $this->assertTrue($second['already_existed']);
        $this->assertSame($first['invoice_no'], $second['invoice_no']);
        $this->assertSame($offlineRef, $second['offline_invoice_no']);
        // No duplicate row must exist.
        $this->assertDatabaseCount('sales', 1);
    }

    /** @test B2 */
    public function seeded_sale_with_same_client_ref_returns_idempotent_replay_via_pre_check(): void
    {
        // Simulate the state after a first commit whose HTTP response was lost:
        // the sale exists in the DB but Flutter queued it. We seed the row
        // directly (bypassing the API) to represent that committed state, then
        // POST via the API with the same client_ref. The pre-check short-
        // circuits and returns the existing row without entering the transaction.
        $clientRef  = (string) Str::uuid();
        $offlineRef = 'OFF-B1-MAIN-20260715-0020';

        Sale::create([
            'invoice_no'         => 'INV-20260715-001',
            'offline_invoice_no' => $offlineRef,
            'client_ref'         => $clientRef,
            'branch_id'          => $this->branchA->id,
            'register_shift_id'  => $this->shiftA->id,
            'subtotal'           => 20,
            'discount'           => 0,
            'tax'                => 0,
            'total'              => 20,
            'status'             => 'pending',
        ]);

        $res = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadA([
                'client_ref'         => $clientRef,
                'offline_invoice_no' => $offlineRef,
            ]))
            ->assertOk()
            ->json('data');

        $this->assertTrue($res['already_existed']);
        $this->assertSame($offlineRef, $res['offline_invoice_no']);
        $this->assertDatabaseCount('sales', 1);
    }

    /** @test B3 */
    public function same_client_ref_and_offline_invoice_no_returns_idempotent_replay(): void
    {
        // Covers the most common retry scenario: a queued sale whose first
        // attempt succeeded server-side but the response was lost (timeout).
        // The retry sends the same client_ref AND same offline_invoice_no.
        $clientRef  = (string) Str::uuid();
        $offlineRef = 'OFF-B1-MAIN-20260715-0030';
        $payload    = $this->payloadA([
            'client_ref'         => $clientRef,
            'offline_invoice_no' => $offlineRef,
        ]);

        $first  = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $payload)
            ->assertOk()->json('data');

        $second = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $payload)
            ->assertOk()->json('data');

        $this->assertTrue($second['already_existed']);
        $this->assertSame($first['invoice_no'], $second['invoice_no']);
        $this->assertDatabaseCount('sales', 1);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Group C: offline_invoice_no collision (the core production bug)
    // ═════════════════════════════════════════════════════════════════════════

    /** @test C1 */
    public function different_client_ref_with_same_offline_invoice_no_returns_409(): void
    {
        // Terminal A commits a sale with offline_invoice_no=0001 and client_ref=UUID-A.
        $offlineRef  = 'OFF-B1-MAIN-20260715-0001';
        $clientRefA  = (string) Str::uuid();
        $clientRefB  = (string) Str::uuid(); // a genuinely different terminal/session

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadA([
                'client_ref'         => $clientRefA,
                'offline_invoice_no' => $offlineRef,
            ]))
            ->assertOk();

        // Terminal B (or device-reset) tries to sync a different sale that
        // also generated offline_invoice_no=0001.
        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadA([
                'client_ref'         => $clientRefB,
                'offline_invoice_no' => $offlineRef,
            ]))
            ->assertStatus(409)
            ->assertJson(['code' => 'OFFLINE_INVOICE_NO_COLLISION']);
    }

    /** @test C2 */
    public function collision_409_body_contains_required_diagnostic_fields(): void
    {
        $offlineRef = 'OFF-B1-MAIN-20260715-0002';

        // First sale committed with UUID-A.
        $first = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadA([
                'client_ref'         => (string) Str::uuid(),
                'offline_invoice_no' => $offlineRef,
            ]))
            ->assertOk()
            ->json('data');

        // Second attempt with UUID-B — should 409 with full diagnostic body.
        $body = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadA([
                'client_ref'         => (string) Str::uuid(),
                'offline_invoice_no' => $offlineRef,
            ]))
            ->assertStatus(409)
            ->json();

        // Error code the Flutter sync layer pattern-matches on.
        $this->assertSame('OFFLINE_INVOICE_NO_COLLISION', $body['code']);
        // The colliding offline reference, so the operator knows which terminal.
        $this->assertSame($offlineRef, $body['data']['offline_invoice_no']);
        // The official invoice already assigned, so the operator can look it up.
        $this->assertSame($first['invoice_no'], $body['data']['conflicting_invoice_no']);
        // Top-level success flag must be false.
        $this->assertFalse($body['success']);
    }

    /** @test C3 */
    public function collision_409_does_not_insert_a_duplicate_sale(): void
    {
        $offlineRef = 'OFF-B1-MAIN-20260715-0003';

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadA([
                'client_ref'         => (string) Str::uuid(),
                'offline_invoice_no' => $offlineRef,
            ]))
            ->assertOk();

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadA([
                'client_ref'         => (string) Str::uuid(),
                'offline_invoice_no' => $offlineRef,
            ]))
            ->assertStatus(409);

        // Exactly one sale row — the collision attempt must not have been committed.
        $this->assertDatabaseCount('sales', 1);
    }

    /** @test C4 */
    public function multiple_sales_with_null_offline_invoice_no_are_allowed(): void
    {
        // The UNIQUE constraint on offline_invoice_no must allow multiple NULLs
        // (standard SQL: each NULL is distinct from all others). Online-only
        // sales do not generate an offline reference, so this must never block.
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->cashierA, 'sanctum')
                ->postJson('/api/v1/sales', $this->payloadA())
                ->assertOk();
        }

        $this->assertDatabaseCount('sales', 3);
        $this->assertDatabaseMissing('sales', ['offline_invoice_no' => 'anything']);
    }

    /** @test C5 */
    public function offline_refs_from_different_branches_never_collide(): void
    {
        // The offline reference encodes the branch ID, so OFF-B1-MAIN-…-0001
        // and OFF-B2-MAIN-…-0001 are different strings and cannot collide.
        $date = now()->format('Ymd');

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadA([
                'offline_invoice_no' => "OFF-B{$this->branchA->id}-MAIN-{$date}-0001",
            ]))
            ->assertOk();

        // Branch B's cashier submitting an equivalent format with B's own ID.
        $this->actingAs($this->cashierB, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadB([
                'offline_invoice_no' => "OFF-B{$this->branchB->id}-MAIN-{$date}-0001",
            ]))
            ->assertOk();

        $this->assertDatabaseCount('sales', 2);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Group D: verify-batch reconciliation
    // ═════════════════════════════════════════════════════════════════════════

    /** @test D1 */
    public function verify_batch_returns_found_entry_with_both_invoice_references(): void
    {
        $clientRef  = (string) Str::uuid();
        $offlineRef = 'OFF-B1-MAIN-20260715-0040';

        $sale = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadA([
                'client_ref'         => $clientRef,
                'offline_invoice_no' => $offlineRef,
            ]))
            ->assertOk()
            ->json('data.sale');

        $batch = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales/verify-batch', ['client_refs' => [$clientRef]])
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $batch['found']);
        $this->assertSame($clientRef, $batch['found'][0]['client_ref']);
        $this->assertSame($sale['invoice_no'], $batch['found'][0]['invoice_no']);
        $this->assertSame($offlineRef, $batch['found'][0]['offline_invoice_no']);
        $this->assertEmpty($batch['missing']);
    }

    /** @test D2 */
    public function verify_batch_reports_unknown_client_ref_as_missing(): void
    {
        $unknownRef = (string) Str::uuid();

        $batch = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales/verify-batch', ['client_refs' => [$unknownRef]])
            ->assertOk()
            ->json('data');

        $this->assertEmpty($batch['found']);
        $this->assertContains($unknownRef, $batch['missing']);
    }

    /** @test D3 */
    public function verify_batch_returns_missing_for_collision_victims_client_ref(): void
    {
        // The stuck queue item has client_ref=UUID-B but the DB row was committed
        // with client_ref=UUID-A (they share an offline_invoice_no). A verify-batch
        // call for UUID-B must return it as missing, confirming to the sync layer
        // that the queued item's OWN client_ref is not recorded server-side.
        $offlineRef = 'OFF-B1-MAIN-20260715-0050';
        $clientRefA = (string) Str::uuid(); // committed in DB
        $clientRefB = (string) Str::uuid(); // stuck queue item (different terminal)

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadA([
                'client_ref'         => $clientRefA,
                'offline_invoice_no' => $offlineRef,
            ]))
            ->assertOk();

        // Terminal B's queued item's client_ref is NOT in the database.
        $batch = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales/verify-batch', ['client_refs' => [$clientRefB]])
            ->assertOk()
            ->json('data');

        $this->assertEmpty($batch['found']);
        $this->assertContains($clientRefB, $batch['missing']);

        // The committed sale's client_ref IS in the database (control).
        $batchA = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales/verify-batch', ['client_refs' => [$clientRefA]])
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $batchA['found']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Group E: Search & branch isolation
    // ═════════════════════════════════════════════════════════════════════════

    /** @test E1 */
    public function search_by_offline_invoice_no_returns_correct_sale(): void
    {
        $offlineRef = 'OFF-B1-MAIN-20260715-0060';

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadA([
                'offline_invoice_no' => $offlineRef,
            ]))
            ->assertOk();

        $results = $this->actingAs($this->cashierA, 'sanctum')
            ->getJson("/api/v1/sales?search={$offlineRef}")
            ->assertOk()
            ->json('data.data');

        $this->assertCount(1, $results);
        $this->assertSame($offlineRef, $results[0]['offline_invoice_no']);
    }

    /** @test E2 */
    public function search_by_offline_invoice_no_is_branch_scoped(): void
    {
        // Branch A creates a sale with a unique offline reference.
        $offlineRef = 'OFF-B1-MAIN-20260715-0070';

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->payloadA([
                'offline_invoice_no' => $offlineRef,
            ]))
            ->assertOk();

        // Branch A cashier can find it.
        $foundA = $this->actingAs($this->cashierA, 'sanctum')
            ->getJson("/api/v1/sales?search={$offlineRef}")
            ->assertOk()
            ->json('data.data');
        $this->assertCount(1, $foundA);

        // Branch B cashier gets zero results — branch scoping must apply.
        $foundB = $this->actingAs($this->cashierB, 'sanctum')
            ->getJson("/api/v1/sales?search={$offlineRef}")
            ->assertOk()
            ->json('data.data');
        $this->assertCount(0, $foundB);
    }
}
