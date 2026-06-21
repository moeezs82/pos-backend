<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printer_settings', function (Blueprint $table) {
            // JSON array of footer lines; each element is one centred line
            // printed at the bottom of every receipt for this branch.
            $table->json('footer_lines')->nullable()->after('shop_phone');
        });
    }

    public function down(): void
    {
        Schema::table('printer_settings', function (Blueprint $table) {
            $table->dropColumn('footer_lines');
        });
    }
};
