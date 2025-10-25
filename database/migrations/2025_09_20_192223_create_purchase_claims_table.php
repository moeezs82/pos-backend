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
        Schema::create('purchase_claims', function (Blueprint $table) {
            $table->id();
            $table->string('claim_no')->unique(); // PCL-YYYYMM-####
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();

            // Why claim?
            $table->enum('type', ['shortage','damaged','wrong_item','expired','other'])->default('other');

            $table->enum('status', ['pending','approved','rejected','closed'])->default('pending');

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->text('reason')->nullable();

            // Audit
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->foreign('purchase_id')->references('id')->on('purchases')->cascadeOnDelete();
            $table->foreign('vendor_id')->references('id')->on('vendors')->restrictOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['branch_id', 'status']);
            $table->index(['vendor_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_claims');
    }
};
