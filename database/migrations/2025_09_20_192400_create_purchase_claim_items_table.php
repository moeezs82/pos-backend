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
        Schema::create('purchase_claim_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_claim_id');
            $table->unsignedBigInteger('purchase_item_id');
            $table->unsignedBigInteger('product_id');

            $table->integer('quantity'); // claimed qty
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // If TRUE, stock will be reduced on approval (e.g., damaged return to vendor).
            // For 'shortage' claims (items never arrived), set FALSE.
            $table->boolean('affects_stock')->default(true);

            // Optional traceability
            $table->string('batch_no')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('remarks')->nullable();

            $table->timestamps();

            $table->foreign('purchase_claim_id')->references('id')->on('purchase_claims')->cascadeOnDelete();
            $table->foreign('purchase_item_id')->references('id')->on('purchase_items')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();

            $table->index(['purchase_claim_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_claim_items');
    }
};
