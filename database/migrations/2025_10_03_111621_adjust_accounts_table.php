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
        Schema::table('accounts', function (Blueprint $t) {
            $t->foreignId('account_type_id')->constrained()->cascadeOnDelete();
            $t->boolean('is_leaf')->default(true); // for grouping if you want a tree
            $t->dropForeign(['branch_id']);
            $t->dropColumn(['type', 'branch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->dropForeign(['account_type_id']);
            $t->dropColumn(['account_type_id', 'is_leaf']);
        });
    }
};
