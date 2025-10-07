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
            CREATE OR REPLACE VIEW v_customer_ar AS
            SELECT s.customer_id,
                   COALESCE(SUM(s.total),0) - COALESCE((
                      SELECT SUM(ra.amount) FROM receipt_allocations ra
                      JOIN receipts r ON r.id = ra.receipt_id
                      WHERE ra.sale_id IN (SELECT id FROM sales WHERE customer_id = s.customer_id)
                   ),0) AS balance
            FROM sales s
            GROUP BY s.customer_id
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_customer_ar');
    }
};
