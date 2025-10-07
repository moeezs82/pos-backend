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
        Schema::table('sales', function (Blueprint $t) {
            $t->decimal('cogs', 18, 2)->default(0);     // for quick reporting
            $t->decimal('gross_profit', 18, 2)->default(0);

            // no "status" here, per your direction
            $t->date('invoice_date')->default(DB::raw('CURRENT_DATE'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['cogs', 'gross_profit']);
        });
    }
};
