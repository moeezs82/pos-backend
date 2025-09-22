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
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->date('txn_date');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->enum('type', ['receipt', 'payment', 'expense', 'transfer_in', 'transfer_out']);
            $table->decimal('amount', 15, 2);

            // links to customers/vendors (optional)
            $table->nullableMorphs('counterparty'); // counterparty_type, counterparty_id

            // link back to source doc (Payment / PurchasePayment)
            $table->nullableMorphs('source'); // source_type, source_id

            $table->string('method')->nullable();     // cash, card, bank, wallet
            $table->string('reference')->nullable();  // invoice no, cheque, etc.
            $table->string('voucher_no')->nullable(); // transfer pair
            $table->text('note')->nullable();

            $table->enum('status', ['pending', 'approved', 'void'])->default('approved');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['txn_date', 'account_id', 'branch_id']);
            $table->index(['voucher_no']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
    }
};
