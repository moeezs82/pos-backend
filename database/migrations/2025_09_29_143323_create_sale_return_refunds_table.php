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
        Schema::create('sale_return_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_return_id');
            $table->decimal('amount', 14, 2);
            $table->string('method')->nullable();       // cash/card/bank/wallet
            $table->string('reference')->nullable();    // receipt no, etc.
            $table->date('refunded_at')->nullable();
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
        Schema::dropIfExists('sale_return_refunds');
    }
};
