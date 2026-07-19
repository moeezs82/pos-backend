<?php

namespace Tests\Feature;

use App\Models\AccountType;
use App\Models\Customer;
use App\Models\Vendor;
use App\Services\PartyBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves Customer/Vendor commercial (trade) balances contain ONLY AR/AP
 * activity, while loans stay in a separate Loans Receivable subledger — the
 * exact contamination reported in the handoff screenshots.
 */
class PartyTradeLoanSeparationTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchId = (int) DB::table('branches')->insertGetId([
            'name' => 'Main', 'is_active' => 1,
        ]);

        $assetTypeId = AccountType::firstOrCreate(['code' => 'ASSET'], ['name' => 'Asset'])->id;
        $liabTypeId  = AccountType::firstOrCreate(['code' => 'LIABILITY'], ['name' => 'Liability'])->id;

        $this->account('1200', 'Accounts Receivable', $assetTypeId);
        $this->account('2000', 'Accounts Payable', $liabTypeId);
        $this->account('1300', 'Loans Receivable', $assetTypeId);
    }

    private function account(string $code, string $name, int $typeId): int
    {
        $existing = DB::table('accounts')->where('code', $code)->value('id');
        if ($existing) {
            return (int) $existing;
        }
        return (int) DB::table('accounts')->insertGetId([
            'code' => $code, 'name' => $name, 'account_type_id' => $typeId,
            'type' => 'cash', 'is_active' => 1,
        ]);
    }

    private function accountId(string $code): int
    {
        return (int) DB::table('accounts')->where('code', $code)->value('id');
    }

    private function post(string $partyType, int $partyId, string $accountCode, float $debit, float $credit): void
    {
        $jeId = DB::table('journal_entries')->insertGetId([
            'entry_date' => now()->toDateString(),
            'memo' => 'test',
            'branch_id' => $this->branchId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_postings')->insert([
            'journal_entry_id' => $jeId,
            'account_id' => $this->accountId($accountCode),
            'debit' => $debit, 'credit' => $credit,
            'party_type' => $partyType, 'party_id' => $partyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_customer_trade_balance_excludes_loans(): void
    {
        $customer = Customer::factory()->create(['branch_id' => $this->branchId]);

        // Trade AR: sale debit 500, receipts credit 100 + 200.
        $this->post('customer', $customer->id, '1200', 500, 0);
        $this->post('customer', $customer->id, '1200', 0, 100);
        $this->post('customer', $customer->id, '1200', 0, 200);

        // Loan (1300): given 1,000 debit, recovered 10,000 credit — same party.
        $this->post('customer', $customer->id, '1300', 1000, 0);
        $this->post('customer', $customer->id, '1300', 0, 10000);

        $svc = new PartyBalanceService();
        $trade = $svc->customerTrade($customer->id, $this->branchId);
        $loan  = $svc->loanSummary('customer', $customer->id, $this->branchId);

        // Trade is AR-only.
        $this->assertSame(500.0, $trade['trade_debit']);
        $this->assertSame(300.0, $trade['trade_credit']);
        $this->assertSame(200.0, $trade['trade_balance']);

        // Loan is separate and negative (over-recovered => review).
        $this->assertSame(1000.0, $loan['loan_given']);
        $this->assertSame(10000.0, $loan['loan_recovered']);
        $this->assertSame(-9000.0, $loan['loan_balance']);
        $this->assertSame('credit_review', $loan['status']);

        // Loan ledger endpoint data reconciles to the loan summary, not AR.
        $ledger = $svc->loanLedger([
            'party_type' => 'customer', 'party_id' => $customer->id,
            'branch_id' => $this->branchId, 'per_page' => 50,
        ]);
        $this->assertCount(2, $ledger['items']);
        $this->assertSame(-9000.0, $ledger['summary']['loan_balance']);
    }

    public function test_vendor_trade_balance_excludes_loans(): void
    {
        $vendor = Vendor::factory()->create(['branch_id' => $this->branchId]);

        // Trade AP: purchase credit 1,500, payment debit 300 => payable 1,200.
        $this->post('vendor', $vendor->id, '2000', 0, 1500);
        $this->post('vendor', $vendor->id, '2000', 300, 0);

        // Loan tagged to same vendor must not touch AP.
        $this->post('vendor', $vendor->id, '1300', 5000, 0);

        $svc = new PartyBalanceService();
        $trade = $svc->vendorTrade($vendor->id, $this->branchId);
        $loan  = $svc->loanSummary('vendor', $vendor->id, $this->branchId);

        $this->assertSame(1500.0, $trade['trade_credit']);
        $this->assertSame(300.0, $trade['trade_debit']);
        $this->assertSame(1200.0, $trade['trade_balance']);

        $this->assertSame(5000.0, $loan['loan_balance']);
    }

    public function test_other_accounts_do_not_contaminate_trade(): void
    {
        $customer = Customer::factory()->create(['branch_id' => $this->branchId]);
        // Only a loan posting exists — trade must be exactly zero.
        $this->post('customer', $customer->id, '1300', 2500, 0);

        $trade = (new PartyBalanceService())->customerTrade($customer->id, $this->branchId);
        $this->assertSame(0.0, $trade['trade_balance']);
        $this->assertSame(0.0, $trade['trade_debit']);
        $this->assertSame(0.0, $trade['trade_credit']);
    }
}
