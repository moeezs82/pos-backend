<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\PaymentMethodAccount;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PaymentMethodAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $cash = Account::firstOrCreate(['code'=>'CASH-01'], ['name'=>'Cash Drawer #1','type'=>'cash']);
        $bank = Account::firstOrCreate(['code'=>'BANK-01'], ['name'=>'Main Bank','type'=>'bank']);

        PaymentMethodAccount::firstOrCreate(['method'=>'cash','branch_id'=>null], ['account_id'=>$cash->id]);
        PaymentMethodAccount::firstOrCreate(['method'=>'card','branch_id'=>null], ['account_id'=>$bank->id]);
        PaymentMethodAccount::firstOrCreate(['method'=>'bank','branch_id'=>null], ['account_id'=>$bank->id]);
        PaymentMethodAccount::firstOrCreate(['method'=>'wallet','branch_id'=>null], ['account_id'=>$bank->id]);

//         INSERT INTO payment_method_accounts (method, account_id, branch_id, created_at, updated_at)
// SELECT 'cash', a.id, NULL, NOW(), NOW()
// FROM accounts a WHERE a.code='CASH-01'
// ON DUPLICATE KEY UPDATE account_id=VALUES(account_id);

//         INSERT INTO payment_method_accounts (method, account_id, branch_id, created_at, updated_at)
// SELECT 'bank', a.id, NULL, NOW(), NOW()
// FROM accounts a WHERE a.code='BANK-01'
// ON DUPLICATE KEY UPDATE account_id=VALUES(account_id);
    }
}
