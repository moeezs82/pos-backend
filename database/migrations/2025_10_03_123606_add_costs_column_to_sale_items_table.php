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
        Schema::table('sale_items', function (Blueprint $t) {
            $t->decimal('unit_cost', 18, 4)->default(0);
            $t->decimal('line_cost', 18, 2)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $t) {
            $t->dropColumn('unit_cost');
            $t->dropColumn('line_cost');
        });
    }
};
