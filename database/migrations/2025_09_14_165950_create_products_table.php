<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();          // Stock Keeping Unit
            $table->string('barcode')->nullable();    // Barcode/QR Code
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();

            // Pricing
            $table->decimal('price', 12, 2);          // Selling price
            $table->decimal('cost_price', 12, 2)->nullable();  // Purchase cost
            $table->decimal('wholesale_price', 12, 2)->nullable();

            // Inventory
            $table->integer('stock_qty')->default(0); // Available stock
            $table->integer('reorder_level')->default(0); // Minimum alert level
            $table->boolean('track_inventory')->default(true);

            // Tax & Discount
            $table->decimal('tax_rate', 5, 2)->default(0.00); // % tax
            $table->boolean('tax_inclusive')->default(true);
            $table->decimal('discount', 12, 2)->nullable();

            // Multi-branch (future: product stock per branch)
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Foreign keys
            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
            $table->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
