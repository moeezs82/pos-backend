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
        Schema::create('purchase_claim_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_claim_id');
            $table->decimal('amount', 14, 2);
            $table->string('method')->nullable();
            $table->string('reference')->nullable();
            $table->date('received_at')->nullable();
            $table->unsignedBigInteger('cash_transaction_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_claim_receipts');
    }
};
