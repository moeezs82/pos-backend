<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Idempotency key for offline-sale sync (see handover doc §1.1).
     * Nullable because normal online sales made while connected don't
     * strictly need one; the app can send one for every sale though
     * (see §2.2), and when it does, `unique()` is what actually stops a
     * duplicate row being created on retry / double-tap / re-run.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->uuid('client_ref')->nullable()->unique()->after('invoice_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['client_ref']);
            $table->dropColumn('client_ref');
        });
    }
};
