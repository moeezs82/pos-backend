<?php

namespace Tests\Feature;

use App\Models\AccountType;
use App\Models\Customer;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Latest-page pagination contract for the trade ledger:
 *  - ascending accounting order with a deterministic id tie-breaker;
 *  - latest=1 resolves to the real last page from the filtered query;
 *  - out-of-range numeric page clamps to the last page;
 *  - running balances stay correct and totals are pagination-independent.
 */
class LedgerPaginationTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;
    private int $arId;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchId = (int) DB::table('branches')->insertGetId(['name' => 'Main', 'is_active' => 1]);
        $assetType = AccountType::firstOrCreate(['code' => 'ASSET'], ['name' => 'Asset'])->id;
        $this->arId = (int) DB::table('accounts')->insertGetId([
            'code' => '1200', 'name' => 'Accounts Receivable', 'account_type_id' => $assetType, 'is_active' => 1,
        ]);
        $this->customer = Customer::factory()->create(['branch_id' => $this->branchId]);
    }

    /** Post an AR debit; $seq controls created_at ordering (and thus accounting order). */
    private function arDebit(float $amount, int $seq): int
    {
        $ts = Carbon::parse('2026-01-01 09:00:00')->addSeconds($seq);
        $jeId = DB::table('journal_entries')->insertGetId([
            'entry_date' => '2026-01-01', 'memo' => "row $seq", 'branch_id' => $this->branchId,
            'created_at' => $ts, 'updated_at' => $ts,
        ]);
        return (int) DB::table('journal_postings')->insertGetId([
            'journal_entry_id' => $jeId, 'account_id' => $this->arId,
            'debit' => $amount, 'credit' => 0,
            'party_type' => 'customer', 'party_id' => $this->customer->id,
            'created_at' => $ts, 'updated_at' => $ts,
        ]);
    }

    private function ledger(array $extra = []): array
    {
        return (new LedgerService())->getLedger(array_merge([
            'party_type' => 'customer', 'customer_id' => $this->customer->id,
            'branch_id' => $this->branchId, 'per_page' => 3,
        ], $extra));
    }

    public function test_latest_returns_last_page_with_newest_rows(): void
    {
        for ($i = 1; $i <= 7; $i++) $this->arDebit(100, $i); // 7 rows, per_page 3 => 3 pages

        $res = $this->ledger(['latest' => true]);

        $this->assertSame(3, $res['last_page']);
        $this->assertSame(3, $res['current_page']);
        $this->assertCount(1, $res['items']); // last page has the single newest row
        // Running balance after the final row equals total debits (7 * 100).
        $this->assertSame(700.0, $res['items'][0]['balance']);
        $this->assertSame(600.0, $res['opening_for_page']); // 6 rows before this page
    }

    public function test_numeric_and_out_of_range_pages(): void
    {
        for ($i = 1; $i <= 7; $i++) $this->arDebit(100, $i);

        $mid = $this->ledger(['page' => 2]);
        $this->assertSame(2, $mid['current_page']);
        $this->assertCount(3, $mid['items']);
        $this->assertSame(400.0, $mid['items'][0]['balance']); // row 4 => 4*100

        // Out-of-range clamps to the real last page (no empty impossible page).
        $over = $this->ledger(['page' => 99]);
        $this->assertSame(3, $over['current_page']);
        $this->assertSame(3, $over['last_page']);
        $this->assertNotEmpty($over['items']);
    }

    public function test_totals_are_pagination_independent(): void
    {
        for ($i = 1; $i <= 7; $i++) $this->arDebit(100, $i);

        $p1 = $this->ledger(['page' => 1]);
        $last = $this->ledger(['latest' => true]);

        $this->assertSame($p1['total'], $last['total']);
        $this->assertSame(7, $p1['total']);
        // Closing balance (last row of last page) is independent of the page viewed.
        $this->assertSame(700.0, $last['items'][0]['balance']);
    }

    public function test_stable_order_on_identical_timestamps(): void
    {
        // Two postings sharing the exact same effective timestamp must order by id.
        $a = $this->arDebit(50, 5);
        $b = $this->arDebit(70, 5); // same seq => same created_at
        $this->assertLessThan($b, $a);

        $res = $this->ledger(['per_page' => 50]);
        $ids = array_map(fn ($r) => $r['posting_id'], $res['items']);
        $posA = array_search($a, $ids, true);
        $posB = array_search($b, $ids, true);
        $this->assertLessThan($posB, $posA); // smaller id appears first, deterministically
    }

    public function test_empty_ledger_reports_one_page(): void
    {
        $res = $this->ledger(['latest' => true]);
        $this->assertSame(0, $res['total']);
        $this->assertSame(1, $res['last_page']);
        $this->assertSame(1, $res['current_page']);
        $this->assertSame([], $res['items']->all());
    }
}
