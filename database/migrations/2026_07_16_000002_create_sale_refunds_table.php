<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('register_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_transaction_id')->nullable()->constrained('cash_transactions')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('method', 20);
            $table->string('reference')->nullable();
            $table->dateTime('refunded_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['register_shift_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_refunds');
    }
};
