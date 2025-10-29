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
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('discount', 15, 2)->nullable()->default(0)->after('price')->comment('discount in percentage for item');
        });
        Schema::table('purchase_claim_items', function (Blueprint $table) {
            $table->decimal('discount', 15, 2)->nullable()->default(0)->after('price')->comment('discount in percentage for item');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn('discount');
        });
        Schema::table('purchase_claim_items', function (Blueprint $table) {
            $table->dropColumn('discount');
        });
    }
};
