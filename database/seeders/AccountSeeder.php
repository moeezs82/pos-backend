<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
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
            // Clearing / holding asset accounts for non-cash tenders so KNET and
            // Card can be reconciled to Bank later without hard-coding the mapping.
            ['code' => '1015', 'name' => 'Cheques in Hand', 'account_type_id' => $map('ASSET')],
            ['code' => '1020', 'name' => 'KNET Clearing',   'account_type_id' => $map('ASSET')],
            ['code' => '1030', 'name' => 'Card Clearing',   'account_type_id' => $map('ASSET')],
            // Add the canonical AR account your services reference:
            ['code' => '1200', 'name' => 'Accounts Receivable (legacy)', 'account_type_id' => $map('ASSET')],
            ['code' => '1210', 'name' => 'Delivery Boy Cash in Transit', 'account_type_id' => $map('ASSET')],
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
            ['code' => '5205', 'name' => 'Other Expenses', 'account_type_id' => $map('EXPENSE')],
        ];

        foreach ($accounts as $a) {
            Account::firstOrCreate(['code' => $a['code']], $a);
        }

        // Payment method -> default configuration (global templates, branch_id null).
        // affects_cash_drawer is stored, never inferred from the word "cash".
        $hasConfigCols = \Illuminate\Support\Facades\Schema::hasColumn('payment_method_accounts', 'affects_cash_drawer');

        $methodDefs = [
            ['method' => 'cash',   'code' => '1000', 'name' => 'Cash',          'drawer' => true,  'sort' => 1, 'icon' => 'cash'],
            ['method' => 'bank',   'code' => '1010', 'name' => 'Bank Transfer', 'drawer' => false, 'sort' => 2, 'icon' => 'bank'],
            ['method' => 'card',   'code' => '1030', 'name' => 'Card',          'drawer' => false, 'sort' => 3, 'icon' => 'card'],
            ['method' => 'knet',   'code' => '1020', 'name' => 'KNET',          'drawer' => false, 'sort' => 4, 'icon' => 'knet'],
            ['method' => 'wallet', 'code' => '1010', 'name' => 'Wallet',        'drawer' => false, 'sort' => 5, 'icon' => 'wallet'],
            ['method' => 'cheque', 'code' => '1015', 'name' => 'Cheque',        'drawer' => false, 'sort' => 6, 'icon' => 'cheque'],
        ];

        foreach ($methodDefs as $def) {
            $account = Account::where('code', $def['code'])->first();
            if (!$account) {
                $account = Account::create([
                    'account_type_id' => AccountType::where('code', 'ASSET')->first()->id,
                    'code' => $def['code'],
                    'name' => ucfirst($def['method']) . ' Default Account',
                ]);
            }

            $extra = ['account_id' => $account->id];
            if ($hasConfigCols) {
                $extra += [
                    'display_name'        => $def['name'],
                    'is_active'           => true,
                    'affects_cash_drawer' => $def['drawer'],
                    'sort_order'          => $def['sort'],
                    'icon_key'            => $def['icon'],
                ];
            }

            PaymentMethodAccount::firstOrCreate(
                ['method' => $def['method'], 'branch_id' => null],
                $extra
            );
        }

        // Fresh installations seed the first branch before account templates.
        // Copy those templates explicitly so runtime posting never needs a
        // cross-branch/global fallback.
        if (\Illuminate\Support\Facades\Schema::hasColumn('payment_method_accounts', 'is_inherited')) {
            $cols = $hasConfigCols
                ? ['method', 'account_id', 'display_name', 'is_active', 'affects_cash_drawer', 'sort_order', 'icon_key']
                : ['method', 'account_id'];

            $templates = PaymentMethodAccount::whereNull('branch_id')->get($cols);
            foreach (Branch::query()->pluck('id') as $branchId) {
                foreach ($templates as $template) {
                    $extra = ['account_id' => $template->account_id, 'is_inherited' => true];
                    if ($hasConfigCols) {
                        $extra += [
                            'display_name'        => $template->display_name,
                            'is_active'           => $template->is_active,
                            'affects_cash_drawer' => $template->affects_cash_drawer,
                            'sort_order'          => $template->sort_order,
                            'icon_key'            => $template->icon_key,
                        ];
                    }

                    PaymentMethodAccount::firstOrCreate(
                        ['method' => $template->method, 'branch_id' => $branchId],
                        $extra
                    );
                }
            }
        }
    }
}
