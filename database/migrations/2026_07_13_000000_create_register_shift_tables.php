<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('device_identifier')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('register_shifts', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_ref')->unique();
            $table->foreignId('register_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['open', 'closed', 'force_closed'])->default('open');
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->decimal('opening_cash', 15, 2);
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('counted_cash', 15, 2)->nullable();
            $table->decimal('variance', 15, 2)->nullable();
            $table->string('device_identifier')->nullable();
            $table->text('opening_note')->nullable();
            $table->text('closing_note')->nullable();
            $table->text('approval_note')->nullable();
            $table->unsignedInteger('pending_sync_count')->default(0);
            $table->boolean('pending_sync_accepted')->default(false);
            $table->timestamps();
            $table->index(['branch_id', 'status']);
            $table->index(['register_id', 'status']);
            $table->index(['cashier_id', 'status']);
        });

        $now = now();
        foreach (DB::table('branches')->select('id')->get() as $branch) {
            DB::table('registers')->insert([
                'branch_id' => $branch->id, 'name' => 'Main Register', 'code' => 'MAIN',
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::create('shift_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_ref')->unique();
            $table->foreignId('register_shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 15, 2);
            $table->string('reason');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('occurred_at');
            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('register_shift_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
        });
        Schema::table('receipts', function (Blueprint $table) {
            $table->foreignId('register_shift_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
        });
        Schema::table('cash_ledger_entries', function (Blueprint $table) {
            $table->foreignId('register_shift_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
        });
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->foreignId('register_shift_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
        });
        Schema::table('sale_return_refunds', function (Blueprint $table) {
            $table->foreignId('register_shift_id')->nullable()->after('sale_return_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_return_refunds', fn (Blueprint $t) => $t->dropConstrainedForeignId('register_shift_id'));
        Schema::table('cash_transactions', fn (Blueprint $t) => $t->dropConstrainedForeignId('register_shift_id'));
        Schema::table('cash_ledger_entries', fn (Blueprint $t) => $t->dropConstrainedForeignId('register_shift_id'));
        Schema::table('receipts', fn (Blueprint $t) => $t->dropConstrainedForeignId('register_shift_id'));
        Schema::table('sales', fn (Blueprint $t) => $t->dropConstrainedForeignId('register_shift_id'));
        Schema::dropIfExists('shift_cash_movements');
        Schema::dropIfExists('register_shifts');
        Schema::dropIfExists('registers');
    }
};
