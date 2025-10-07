<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("
            CREATE OR REPLACE VIEW v_trial_balance AS
            SELECT a.id as account_id, a.code, a.name,
                   SUM(p.debit)  AS total_debit,
                   SUM(p.credit) AS total_credit,
                   SUM(p.debit - p.credit) AS balance
            FROM accounts a
            LEFT JOIN journal_postings p ON p.account_id = a.id
            GROUP BY a.id, a.code, a.name
            ORDER BY a.code
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_trial_balance');
    }
};
