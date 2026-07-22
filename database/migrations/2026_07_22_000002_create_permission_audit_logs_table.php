<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('action', 100)->index();
            $table->string('target_type');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->unsignedBigInteger('access_token_id')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['target_type', 'target_id']);
            $table->index(['branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_audit_logs');
    }
};
