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
        Schema::create('payment_method_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('method'); // cash, card, bank, wallet, transfer...
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->timestamps();

            $table->unique(['method','branch_id']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_method_accounts');
    }
};
