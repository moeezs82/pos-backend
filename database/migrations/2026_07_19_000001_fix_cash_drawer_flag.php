<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrects legacy/misconfigured branch payment-method rows where the canonical
 * 'cash' method was stored as NOT drawer-affecting. Cash is physical drawer
 * cash by definition; leaving the flag false caused the register summary to
 * count Cash in expected cash yet label it "non-drawer".
 *
 * Idempotent and additive — only flips the boolean for method='cash' rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_method_accounts')
            && Schema::hasColumn('payment_method_accounts', 'affects_cash_drawer')) {
            DB::table('payment_method_accounts')
                ->where('method', 'cash')
                ->where('affects_cash_drawer', false)
                ->update(['affects_cash_drawer' => true]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: cash must remain drawer-affecting. No-op.
    }
};
