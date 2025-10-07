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
        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->date('entry_date');
            $t->string('memo')->nullable();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->morphs('reference'); // reference_type, reference_id: Sale, Receipt, etc.
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
