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
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('return_no')->unique();
            $table->decimal('subtotal', 15, 2);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
    }
};
