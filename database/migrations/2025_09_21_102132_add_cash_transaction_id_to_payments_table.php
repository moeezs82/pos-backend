<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Sales payments
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments','cash_transaction_id')) {
                $table->unsignedBigInteger('cash_transaction_id')->nullable()->after('reference');
                $table->foreign('cash_transaction_id')->references('id')->on('cash_transactions')->nullOnDelete();
            }
        });

        // Purchase payments
        Schema::table('purchase_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_payments','cash_transaction_id')) {
                $table->unsignedBigInteger('cash_transaction_id')->nullable()->after('tx_ref');
                $table->foreign('cash_transaction_id')->references('id')->on('cash_transactions')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments','cash_transaction_id')) {
                $table->dropForeign(['cash_transaction_id']);
                $table->dropColumn('cash_transaction_id');
            }
        });
        Schema::table('purchase_payments', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_payments','cash_transaction_id')) {
                $table->dropForeign(['cash_transaction_id']);
                $table->dropColumn('cash_transaction_id');
            }
        });
    }
};
