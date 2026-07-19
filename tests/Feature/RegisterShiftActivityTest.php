<?php

namespace Tests\Feature;

use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Register;
use App\Models\RegisterShift;
use App\Models\User;
use App\Services\PaymentMethodService;
use App\Services\RegisterShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reconciles the register shift's expected cash with a unified, read-only
 * activity feed, and proves that the reported cash sale + cash expense both
 * appear without any duplicate manual movement.
 */
class RegisterShiftActivityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $cashier;
    private RegisterShift $shift;
    private int $cashAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Main', 'is_active' => true]);
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);

        $register = Register::create([
            'branch_id' => $this->branch->id, 'name' => 'Reg 1', 'code' => 'REG1', 'is_active' => true,
        ]);
        $this->shift = RegisterShift::create([
            'client_ref' => (string) Str::uuid(), 'register_id' => $register->id,
            'branch_id' => $this->branch->id, 'cashier_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id, 'status' => 'open',
            'opened_at' => now(), 'opening_cash' => 0,
        ]);

        $assetType = AccountType::firstOrCreate(['code' => 'ASSET'], ['name' => 'Asset'])->id;
        $this->cashAccountId = (int) DB::table('accounts')->insertGetId([
            'code' => '1000', 'name' => 'Cash in Hand', 'account_type_id' => $assetType, 'is_active' => 1,
        ]);
    }

    private function cashIn(float $amount): void
    {
        DB::table('receipts')->insert([
            'register_shift_id' => $this->shift->id, 'branch_id' => $this->branch->id,
            'received_at' => now()->toDateString(), 'method' => 'cash', 'amount' => $amount,
            'reference' => 'sale', 'created_by' => $this->cashier->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cashExpense(float $amount): void
    {
        DB::table('cash_ledger_entries')->insert([
            'register_shift_id' => $this->shift->id, 'branch_id' => $this->branch->id,
            'txn_date' => now()->toDateString(), 'category' => 'OTHER_EXPENSE', 'direction' => 'out',
            'amount' => $amount, 'account_id' => $this->cashAccountId, 'method' => 'cash',
            'reference_name' => 'Tea & snacks', 'status' => 'posted', 'created_by' => $this->cashier->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_sale_and_expense_reconcile_and_appear_in_activity(): void
    {
        $this->cashIn(600);       // reported cash sale
        $this->cashExpense(100);  // reported cash Other Expense

        $svc = new RegisterShiftService();
        $summary = $svc->summary($this->shift);
        $this->assertSame(500.0, $summary['expected_cash']);

        $activity = $svc->activity($this->shift);
        $r = $activity['reconciliation'];
        $this->assertSame(600.0, $r['drawer_in']);
        $this->assertSame(100.0, $r['drawer_out']);
        $this->assertSame(500.0, $r['expected_cash']);
        $this->assertTrue($r['is_reconciled']);

        // Both effects are visible as separate rows — no duplicate manual entry.
        $this->assertCount(2, $activity['items']);
        $dirs = array_column($activity['items'], 'direction');
        sort($dirs);
        $this->assertSame(['in', 'out'], $dirs);
    }

    public function test_manual_cash_out_adds_one_row_and_changes_expected(): void
    {
        $this->cashIn(600);
        $this->cashExpense(100);

        // A genuine manual drawer movement (banking) — a separate physical event.
        DB::table('shift_cash_movements')->insert([
            'client_ref' => (string) Str::uuid(), 'register_shift_id' => $this->shift->id,
            'branch_id' => $this->branch->id, 'direction' => 'out', 'amount' => 100,
            'reason' => 'Bank drop', 'created_by' => $this->cashier->id,
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $svc = new RegisterShiftService();
        $this->assertSame(400.0, $svc->summary($this->shift)['expected_cash']);

        $activity = $svc->activity($this->shift);
        $this->assertCount(3, $activity['items']);
        $manual = array_filter($activity['items'], fn ($i) => $i['is_manual'] === true);
        $this->assertCount(1, $manual);
        $this->assertSame(200.0, $activity['reconciliation']['drawer_out']);
    }

    public function test_loading_activity_writes_no_rows(): void
    {
        $this->cashIn(600);
        $this->cashExpense(100);

        $before = [
            'cash_transactions' => DB::table('cash_transactions')->count(),
            'shift_cash_movements' => DB::table('shift_cash_movements')->count(),
            'journal_entries' => DB::table('journal_entries')->count(),
            'cash_ledger_entries' => DB::table('cash_ledger_entries')->count(),
        ];

        $svc = new RegisterShiftService();
        $svc->activity($this->shift);
        $svc->activity($this->shift); // twice — still read-only

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "$table must be unchanged by a read");
        }
    }

    public function test_cash_method_is_reported_drawer_affecting(): void
    {
        $this->cashIn(600);
        $summary = (new RegisterShiftService())->summary($this->shift);
        $cash = collect($summary['method_totals'])->firstWhere('method', 'cash');
        $this->assertNotNull($cash);
        $this->assertTrue($cash['affects_cash_drawer']);

        // Resolver forces canonical cash drawer-affecting even if config says false.
        DB::table('payment_method_accounts')->where('method', 'cash')->update(['affects_cash_drawer' => false]);
        $flags = (new PaymentMethodService())->drawerFlagsForBranch($this->branch->id);
        $this->assertTrue($flags['cash']);
    }
}
