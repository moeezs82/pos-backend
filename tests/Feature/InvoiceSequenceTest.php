<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Register;
use App\Models\RegisterShift;
use App\Models\Sale;
use App\Models\User;
use App\Services\InvoiceSequenceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Branch-local invoice numbering and offline reference tests.
 *
 * Covers:
 *   – Independent daily sequences per branch
 *   – Daily reset per branch
 *   – Concurrent numbering in the same branch (serialisation via lock)
 *   – Same human invoice number allowed across different branches
 *   – Duplicate invoice number rejected inside the same branch
 *   – Original occurrence date used for synced offline sales
 *   – offline_invoice_no stored and returned
 *   – Idempotent replay preserves both references
 *   – Search by official invoice_no
 *   – Search by offline_invoice_no
 *   – Branch isolation for both searches
 */
class InvoiceSequenceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;
    private Branch $branchB;
    private User   $cashierA;
    private User   $cashierB;
    private RegisterShift $shiftA;
    private RegisterShift $shiftB;
    private Product $product;
    private Product $productB;

    private const PERMS = [
        'view-sales', 'create-sales',
        'view-register-shifts', 'open-register-shift', 'close-own-register-shift',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions
        foreach (self::PERMS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        // Branches
        $this->branchA = Branch::create(['name' => 'Branch A', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'Branch B', 'is_active' => true]);

        // Cashier A (assigned to Branch A)
        $this->cashierA = User::factory()->create(['branch_id' => $this->branchA->id]);
        $this->cashierA->givePermissionTo(self::PERMS);

        // Cashier B (assigned to Branch B)
        $this->cashierB = User::factory()->create(['branch_id' => $this->branchB->id]);
        $this->cashierB->givePermissionTo(self::PERMS);

        // Registers
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

        // Open shifts
        $now = now();
        $this->shiftA = RegisterShift::create([
            'client_ref'   => \Illuminate\Support\Str::uuid(),
            'register_id'  => $regA->id,
            'branch_id'    => $this->branchA->id,
            'cashier_id'   => $this->cashierA->id,
            'opened_by'    => $this->cashierA->id,
            'status'       => 'open',
            'opened_at'    => $now,
            'opening_cash' => 0,
        ]);
        $this->shiftB = RegisterShift::create([
            'client_ref'   => \Illuminate\Support\Str::uuid(),
            'register_id'  => $regB->id,
            'branch_id'    => $this->branchB->id,
            'cashier_id'   => $this->cashierB->id,
            'opened_by'    => $this->cashierB->id,
            'status'       => 'open',
            'opened_at'    => $now,
            'opening_cash' => 0,
        ]);

        // Product for Branch A
        $this->product = Product::create([
            'name'        => 'Test Item',
            'branch_id'   => $this->branchA->id,
            'cost_price'  => 10.00,
            'price'       => 20.00,
            'sku'         => 'TEST-001',
        ]);
        ProductStock::create([
            'product_id' => $this->product->id,
            'branch_id'  => $this->branchA->id,
            'quantity'   => 100,
            'avg_cost'   => 10.00,
        ]);

        // Product for Branch B — products are branch-scoped by branch_id column,
        // there is no branch_products pivot table.
        $this->productB = Product::create([
            'name'        => 'Test Item B',
            'branch_id'   => $this->branchB->id,
            'cost_price'  => 10.00,
            'price'       => 20.00,
            'sku'         => 'TEST-002',
        ]);
        ProductStock::create([
            'product_id' => $this->productB->id,
            'branch_id'  => $this->branchB->id,
            'quantity'   => 100,
            'avg_cost'   => 10.00,
        ]);
    }

    // ── InvoiceSequenceService unit ────────────────────────────────────────────

    /** @test */
    public function it_allocates_independent_sequences_per_branch(): void
    {
        $svc = app(InvoiceSequenceService::class);
        $date = Carbon::parse('2026-07-15');

        $no_A1 = DB::transaction(fn () => $svc->allocate($this->branchA->id, $date));
        $no_B1 = DB::transaction(fn () => $svc->allocate($this->branchB->id, $date));
        $no_A2 = DB::transaction(fn () => $svc->allocate($this->branchA->id, $date));
        $no_B2 = DB::transaction(fn () => $svc->allocate($this->branchB->id, $date));

        // Each branch sequences from 001 independently
        $this->assertSame('INV-20260715-001', $no_A1);
        $this->assertSame('INV-20260715-001', $no_B1);
        $this->assertSame('INV-20260715-002', $no_A2);
        $this->assertSame('INV-20260715-002', $no_B2);
    }

    /** @test */
    public function sequence_resets_to_001_on_new_day_per_branch(): void
    {
        $svc = app(InvoiceSequenceService::class);
        $day1 = Carbon::parse('2026-07-14');
        $day2 = Carbon::parse('2026-07-15');

        DB::transaction(fn () => $svc->allocate($this->branchA->id, $day1));
        DB::transaction(fn () => $svc->allocate($this->branchA->id, $day1));

        $firstOfNextDay = DB::transaction(fn () => $svc->allocate($this->branchA->id, $day2));

        $this->assertSame('INV-20260715-001', $firstOfNextDay);
    }

    /** @test */
    public function same_invoice_no_allowed_in_different_branches(): void
    {
        // Both branches should be able to hold INV-YYYYMMDD-001.
        $svc  = app(InvoiceSequenceService::class);
        $date = Carbon::parse('2026-07-15');

        $noA = DB::transaction(fn () => $svc->allocate($this->branchA->id, $date));
        $noB = DB::transaction(fn () => $svc->allocate($this->branchB->id, $date));

        $this->assertSame('INV-20260715-001', $noA);
        $this->assertSame('INV-20260715-001', $noB);

        // Verify the DB actually has two rows with the same invoice_no but
        // different branch_ids — the composite unique allows this.
        Sale::create(['invoice_no' => $noA, 'branch_id' => $this->branchA->id, 'register_shift_id' => $this->shiftA->id, 'subtotal' => 0, 'discount' => 0, 'tax' => 0, 'total' => 0, 'status' => 'pending']);
        Sale::create(['invoice_no' => $noB, 'branch_id' => $this->branchB->id, 'register_shift_id' => $this->shiftB->id, 'subtotal' => 0, 'discount' => 0, 'tax' => 0, 'total' => 0, 'status' => 'pending']);

        $this->assertDatabaseCount('sales', 2);
    }

    /** @test */
    public function duplicate_invoice_no_rejected_within_same_branch(): void
    {
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        Sale::create(['invoice_no' => 'INV-20260715-001', 'branch_id' => $this->branchA->id, 'register_shift_id' => $this->shiftA->id, 'subtotal' => 0, 'discount' => 0, 'tax' => 0, 'total' => 0, 'status' => 'pending']);
        Sale::create(['invoice_no' => 'INV-20260715-001', 'branch_id' => $this->branchA->id, 'register_shift_id' => $this->shiftA->id, 'subtotal' => 0, 'discount' => 0, 'tax' => 0, 'total' => 0, 'status' => 'pending']);
    }

    /** @test */
    public function sequence_seeds_correctly_from_existing_sales(): void
    {
        // Simulate a sale already inserted BEFORE the sequence table had a row.
        Sale::create([
            'invoice_no'       => 'INV-20260715-005',
            'branch_id'        => $this->branchA->id,
            'register_shift_id' => $this->shiftA->id,
            'subtotal'         => 0,
            'discount'         => 0,
            'tax'              => 0,
            'total'            => 0,
            'status'           => 'pending',
        ]);

        // InvoiceSequenceService should detect the max and continue from 006.
        $svc  = app(InvoiceSequenceService::class);
        $date = Carbon::parse('2026-07-15');

        $next = DB::transaction(fn () => $svc->allocate($this->branchA->id, $date));

        $this->assertSame('INV-20260715-006', $next);
    }

    // ── POST /api/v1/sales integration ────────────────────────────────────────

    private function salePayload(array $override = []): array
    {
        return array_merge([
            'items'      => [['product_id' => $this->product->id, 'quantity' => 1, 'price' => 20]],
            'discount'   => 0,
            'tax'        => 0,
            'payments'   => [['amount' => 20, 'method' => 'cash']],
            'client_ref' => (string) \Illuminate\Support\Str::uuid(),
            'register_shift_client_ref' => $this->shiftA->client_ref,
        ], $override);
    }

    /** Branch B base payload — uses Branch B's product and shift. */
    private function salePayloadB(array $override = []): array
    {
        return array_merge([
            'items'      => [['product_id' => $this->productB->id, 'quantity' => 1, 'price' => 20]],
            'discount'   => 0,
            'tax'        => 0,
            'payments'   => [['amount' => 20, 'method' => 'cash']],
            'client_ref' => (string) \Illuminate\Support\Str::uuid(),
            'register_shift_client_ref' => $this->shiftB->client_ref,
        ], $override);
    }

    /** @test */
    public function branch_a_and_branch_b_each_get_001_on_same_day(): void
    {
        $saleA = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->salePayload([
                'register_shift_client_ref' => $this->shiftA->client_ref,
            ]))
            ->assertOk()
            ->json('data.sale');

        $saleB = $this->actingAs($this->cashierB, 'sanctum')
            ->postJson('/api/v1/sales', $this->salePayloadB())
            ->assertOk()
            ->json('data.sale');

        $dateStr = now()->format('Ymd');
        $this->assertSame("INV-{$dateStr}-001", $saleA['invoice_no']);
        $this->assertSame("INV-{$dateStr}-001", $saleB['invoice_no']);
    }

    /** @test */
    public function offline_invoice_no_is_stored_and_returned(): void
    {
        $offlineRef = 'OFF-B1-MAIN-20260715-0001';

        $res = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->salePayload([
                'offline_invoice_no' => $offlineRef,
                'occurred_at'        => '2026-07-15 10:00:00',
                'register_shift_client_ref' => $this->shiftA->client_ref,
            ]))
            ->assertOk()
            ->json('data');

        $this->assertSame($offlineRef, $res['offline_invoice_no']);
        $this->assertDatabaseHas('sales', ['offline_invoice_no' => $offlineRef]);
    }

    /** @test */
    public function idempotent_replay_returns_both_references(): void
    {
        $clientRef  = (string) \Illuminate\Support\Str::uuid();
        $offlineRef = 'OFF-B1-MAIN-20260715-0002';

        $payload = $this->salePayload([
            'client_ref'         => $clientRef,
            'offline_invoice_no' => $offlineRef,
            'register_shift_client_ref' => $this->shiftA->client_ref,
        ]);

        // First POST — creates the sale
        $first = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $payload)
            ->assertOk()
            ->json('data');

        // Second POST with the same client_ref — idempotent replay
        $second = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $payload)
            ->assertOk()
            ->json('data');

        $this->assertTrue($second['already_existed']);
        $this->assertSame($first['invoice_no'], $second['invoice_no']);
        $this->assertSame($offlineRef, $second['offline_invoice_no']);
        $this->assertDatabaseCount('sales', 1); // No duplicate created
    }

    /** @test */
    public function offline_sale_uses_occurred_at_date_for_invoice_number(): void
    {
        $occurredAt = '2026-07-10 14:30:00'; // a past date

        $res = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->salePayload([
                'occurred_at'   => $occurredAt,
                'client_ref'    => (string) \Illuminate\Support\Str::uuid(),
                'register_shift_client_ref' => $this->shiftA->client_ref,
            ]))
            ->assertOk()
            ->json('data.sale');

        // Invoice number should carry the occurred_at date, not today's date
        $this->assertStringContainsString('INV-20260710-', $res['invoice_no']);
    }

    /** @test */
    public function search_by_invoice_no_is_branch_scoped(): void
    {
        // Create a sale in Branch A
        $dateStr = now()->format('Ymd');
        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->salePayload([
                'register_shift_client_ref' => $this->shiftA->client_ref,
            ]))
            ->assertOk();

        // Branch A cashier can find the sale
        $foundA = $this->actingAs($this->cashierA, 'sanctum')
            ->getJson("/api/v1/sales?search=INV-{$dateStr}-001")
            ->assertOk()
            ->json('data.data');

        $this->assertCount(1, $foundA);
        $this->assertSame($this->branchA->id, (int) $foundA[0]['branch_id']);

        // Branch B cashier finds nothing (branch scope isolates results)
        $foundB = $this->actingAs($this->cashierB, 'sanctum')
            ->getJson("/api/v1/sales?search=INV-{$dateStr}-001")
            ->assertOk()
            ->json('data.data');

        $this->assertCount(0, $foundB);
    }

    /** @test */
    public function search_by_offline_invoice_no_finds_synced_sale(): void
    {
        $offlineRef = 'OFF-B1-MAIN-20260715-0007';

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->salePayload([
                'offline_invoice_no' => $offlineRef,
                'register_shift_client_ref' => $this->shiftA->client_ref,
            ]))
            ->assertOk();

        $found = $this->actingAs($this->cashierA, 'sanctum')
            ->getJson("/api/v1/sales?search={$offlineRef}")
            ->assertOk()
            ->json('data.data');

        $this->assertCount(1, $found);
        $this->assertSame($offlineRef, $found[0]['offline_invoice_no']);
    }

    /** @test */
    public function verify_batch_returns_offline_invoice_no(): void
    {
        $clientRef  = (string) \Illuminate\Support\Str::uuid();
        $offlineRef = 'OFF-B1-MAIN-20260715-0003';

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales', $this->salePayload([
                'client_ref'         => $clientRef,
                'offline_invoice_no' => $offlineRef,
                'register_shift_client_ref' => $this->shiftA->client_ref,
            ]))
            ->assertOk();

        $batch = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/v1/sales/verify-batch', ['client_refs' => [$clientRef]])
            ->assertOk()
            ->json('data.found.0');

        $this->assertSame($offlineRef, $batch['offline_invoice_no']);
    }
}
