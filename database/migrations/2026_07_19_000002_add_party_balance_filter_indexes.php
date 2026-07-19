<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports Party Payments server-side balance filtering and stable paging.
 * The balance query starts at the relevant control account + morph type, then
 * groups by party. Party lists remain branch/name ordered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_postings', function (Blueprint $table) {
            $table->index(
                ['account_id', 'party_type', 'party_id', 'journal_entry_id'],
                'idx_party_balance_filter'
            );
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index(['branch_id', 'first_name', 'last_name', 'id'], 'idx_customers_party_payment');
        });
        Schema::table('vendors', function (Blueprint $table) {
            $table->index(['branch_id', 'first_name', 'last_name', 'id'], 'idx_vendors_party_payment');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->index(['branch_id', 'name', 'id'], 'idx_users_party_payment');
        });
    }

    public function down(): void
    {
        Schema::table('journal_postings', fn (Blueprint $table) => $table->dropIndex('idx_party_balance_filter'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropIndex('idx_customers_party_payment'));
        Schema::table('vendors', fn (Blueprint $table) => $table->dropIndex('idx_vendors_party_payment'));
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex('idx_users_party_payment'));
    }
};
