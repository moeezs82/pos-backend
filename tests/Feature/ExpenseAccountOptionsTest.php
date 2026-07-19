<?php

namespace Tests\Feature;

use App\Models\AccountType;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Covers the "empty expense dropdown" fix:
 *  - operational users can read eligible EXPENSE accounts via the dedicated
 *    read-only endpoint (no account-management permission required);
 *  - inactive, non-expense and system accounts (5100/5205) are excluded;
 *  - unauthorized users are denied;
 *  - account-management mutations stay Master-Admin only;
 *  - the Cash Ledger submit guard rejects a system/non-expense/inactive target.
 */
class ExpenseAccountOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private int $assetType;
    private int $expenseType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Main', 'is_active' => true]);

        $this->assetType   = AccountType::firstOrCreate(['code' => 'ASSET'], ['name' => 'Asset'])->id;
        $this->expenseType = AccountType::firstOrCreate(['code' => 'EXPENSE'], ['name' => 'Expense'])->id;

        // Eligible manual expense accounts.
        $this->account('5300', 'Other / Sundry Expense', $this->expenseType, true);
        $this->account('5301', 'Other Expense', $this->expenseType, true);
        // System/inventory-driven expense accounts — must be excluded.
        $this->account('5100', 'Cost of Goods Sold', $this->expenseType, true);
        $this->account('5205', 'Purchase Price Variance', $this->expenseType, true);
        // Inactive expense — excluded.
        $this->account('5399', 'Retired Expense', $this->expenseType, false);
        // Non-expense — excluded.
        $this->account('1000', 'Cash in Hand', $this->assetType, true);

        foreach (['view-cashbook', 'manage-accounts'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
    }

    private function account(string $code, string $name, int $typeId, bool $active): void
    {
        DB::table('accounts')->updateOrInsert(
            ['code' => $code],
            ['name' => $name, 'account_type_id' => $typeId, 'is_active' => $active ? 1 : 0]
        );
    }

    private function user(array $perms): User
    {
        $u = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        if ($perms) $u->givePermissionTo($perms);
        return $u;
    }

    public function test_operational_user_gets_only_eligible_expense_accounts(): void
    {
        $token = $this->user(['view-cashbook'])->createToken('t')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/v1/accounts/expense-options')->assertOk();

        $codes = collect($res->json('data.items'))->pluck('code')->all();
        sort($codes);

        $this->assertSame(['5300', '5301'], $codes);
        $this->assertNotContains('5100', $codes); // system
        $this->assertNotContains('5205', $codes); // system
        $this->assertNotContains('5399', $codes); // inactive
        $this->assertNotContains('1000', $codes); // non-expense
    }

    public function test_unauthorized_user_is_denied(): void
    {
        $token = $this->user([])->createToken('t')->plainTextToken; // no operational perm

        $this->withToken($token)
            ->getJson('/api/v1/accounts/expense-options')
            ->assertForbidden();
    }

    public function test_account_mutation_still_master_admin_only(): void
    {
        // A user with only the operational permission may read, but must not
        // create/modify Chart of Accounts.
        $token = $this->user(['view-cashbook', 'manage-accounts'])->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/accounts', [
                'code' => '5400', 'name' => 'Hacked', 'account_type_id' => $this->expenseType,
            ])
            ->assertForbidden();
    }

    public function test_submit_guard_rejects_system_and_ineligible_accounts(): void
    {
        $svc = app(\App\Services\CashLedgerService::class);
        $method = new \ReflectionMethod($svc, 'assertActiveExpenseAccount');
        $method->setAccessible(true);

        // Eligible passes.
        $ok = $method->invoke($svc, '5300');
        $this->assertSame('5300', (string) $ok->code);

        // System account rejected.
        $this->assertRejected($method, $svc, '5100');
        // Inactive rejected.
        $this->assertRejected($method, $svc, '5399');
        // Non-expense rejected.
        $this->assertRejected($method, $svc, '1000');
    }

    private function assertRejected(\ReflectionMethod $m, object $svc, string $code): void
    {
        try {
            $m->invoke($svc, $code);
            $this->fail("Expected rejection for account $code");
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('expense_account_code', $e->errors());
        }
    }
}
