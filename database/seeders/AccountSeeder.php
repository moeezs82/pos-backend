<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\PaymentMethodAccount;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rows = [
            ['name' => 'Asset',     'code' => 'ASSET'],
            ['name' => 'Liability', 'code' => 'LIABILITY'],
            ['name' => 'Equity',    'code' => 'EQUITY'],
            ['name' => 'Income',    'code' => 'INCOME'],
            ['name' => 'Expense',   'code' => 'EXPENSE'],
        ];
        foreach ($rows as $r) AccountType::firstOrCreate(['code' => $r['code']], $r);

        $map = fn(string $code) => AccountType::where('code', $code)->firstOrFail()->id;

        $accounts = [
            // Assets
            ['code' => '1000', 'name' => 'Cash in Hand', 'account_type_id' => $map('ASSET')],
            ['code' => '1010', 'name' => 'Bank',         'account_type_id' => $map('ASSET')],
            // Add the canonical AR account your services reference:
            ['code' => '1200', 'name' => 'Accounts Receivable (legacy)', 'account_type_id' => $map('ASSET')],
            ['code' => '1400', 'name' => 'Inventory',    'account_type_id' => $map('ASSET')],
            ['code' => '2105', 'name' => 'Input VAT (Recoverable)', 'account_type_id' => $map('ASSET')],

            // Liabilities
            ['code' => '2100', 'name' => 'Sales Tax Payable (legacy)', 'account_type_id' => $map('LIABILITY')],
            // Add Output VAT / Sales Tax Payable used for sales
            ['code' => '2205', 'name' => 'Output VAT (Sales Tax Payable)', 'account_type_id' => $map('LIABILITY')],
            ['code' => '2000', 'name' => 'Accounts Payable',   'account_type_id' => $map('LIABILITY')],

            // Equity
            ['code' => '3100', 'name' => 'Retained Earnings', 'account_type_id' => $map('EQUITY')],

            // Income
            // Add canonical Sales Revenue used in services
            ['code' => '4000', 'name' => 'Sales Revenue', 'account_type_id' => $map('INCOME')],

            // Expenses
            ['code' => '5100', 'name' => 'Cost of Goods Sold', 'account_type_id' => $map('EXPENSE')],
            ['code' => '5205', 'name' => 'Purchase Price Variance', 'account_type_id' => $map('EXPENSE')],
        ];

        foreach ($accounts as $a) {
            Account::firstOrCreate(['code' => $a['code']], $a);
        }

        // Payment method -> default account mapping
        $mapMethods = [
            'cash'   => '1000', // Cash in Hand
            'bank'   => '1010', // Bank
            'card'   => '1010', // Card -> Bank
            'wallet' => '1010', // Wallet -> Bank (or separate wallet account if you want)
        ];

        foreach ($mapMethods as $method => $acctCode) {
            $account = Account::where('code', $acctCode)->first();
            if (!$account) {
                $account = Account::create([
                    'account_type_id' => \App\Models\AccountType::where('code','ASSET')->first()->id,
                    'code' => $acctCode,
                    'name' => ucfirst($method) . ' Default Account',
                ]);
            }

            PaymentMethodAccount::firstOrCreate(
                ['method' => $method, 'branch_id' => null],
                ['account_id' => $account->id]
            );
        }
    }
}
