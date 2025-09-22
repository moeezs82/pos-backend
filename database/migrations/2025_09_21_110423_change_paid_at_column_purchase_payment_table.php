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
        DB::statement("ALTER TABLE purchase_payments 
        MODIFY paid_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP");
        DB::statement("ALTER TABLE payments 
        MODIFY received_on DATETIME NULL DEFAULT CURRENT_DATE");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE purchase_payments 
        MODIFY paid_at DATETIME NULL DEFAULT NULL");
        DB::statement("ALTER TABLE payments 
        MODIFY received_on DATETIME NULL DEFAULT NULL");
    }
};
