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
        Schema::create('vendor_payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_payment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 18, 2);
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_payment_allocations');
    }
};
