<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which invoice template each printer destination uses. Templates are
 * predefined on the frontend (standard / compact / kitchen) — this just
 * stores the chosen key per destination, so the same branch's main printer
 * and kitchen printer can use completely different layouts (e.g. a full
 * receipt up front, item-only ticket in the kitchen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printer_settings', function (Blueprint $table) {
            $table->string('main_invoice_template')->default('standard')->after('local_printer_name');
            $table->string('kitchen_invoice_template')->default('kitchen')->after('kitchen_local_printer_name');
        });
    }

    public function down(): void
    {
        Schema::table('printer_settings', function (Blueprint $table) {
            $table->dropColumn(['main_invoice_template', 'kitchen_invoice_template']);
        });
    }
};
