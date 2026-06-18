<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Printer settings: where receipts actually get printed from.
 *
 * One row per branch (branch_id nullable = single-shop / global default,
 * matching the nullable branch_id convention already used by
 * cash_ledger_entries). Master admin manages this; ordinary branch users
 * only ever read it for the branch they're scoped to.
 *
 * Two independent destinations can be configured at once:
 *   - a network ESC/POS printer (ip + port), used on every platform
 *   - a "local" printer name, meaningful where the OS exposes a print
 *     spooler (currently Windows desktop); harmless elsewhere, just unused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->nullable()->unique()->constrained()->cascadeOnDelete();

            $table->string('shop_name')->nullable();
            $table->string('shop_address')->nullable();
            $table->string('shop_phone')->nullable();

            // Which destination to actually use when a sale completes.
            $table->enum('active_connection', ['network', 'local', 'none'])->default('none');

            // Network (ESC/POS over TCP) — works on every platform.
            $table->string('network_ip')->nullable();
            $table->unsignedInteger('network_port')->default(9100);

            // Local / OS-spooled printer (Windows desktop today).
            $table->string('local_printer_name')->nullable();

            // Optional second copy of the receipt to a kitchen/back printer.
            $table->boolean('kitchen_print_enabled')->default(false);
            $table->string('kitchen_network_ip')->nullable();
            $table->unsignedInteger('kitchen_network_port')->default(9100);
            $table->string('kitchen_local_printer_name')->nullable();

            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_settings');
    }
};
