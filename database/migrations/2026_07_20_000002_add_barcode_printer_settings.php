<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printer_settings', function (Blueprint $table) {
            $table->boolean('barcode_print_enabled')->default(false);
            $table->string('barcode_connection', 20)->default('dialog');
            $table->string('barcode_local_printer_name')->nullable();
            $table->string('barcode_network_ip', 100)->nullable();
            $table->unsignedInteger('barcode_network_port')->default(9100);
            $table->string('barcode_printer_language', 20)->default('driver');
            $table->decimal('barcode_label_width_mm', 6, 2)->default(50);
            $table->decimal('barcode_label_height_mm', 6, 2)->default(30);
            $table->decimal('barcode_label_gap_mm', 5, 2)->default(2);
            $table->unsignedSmallInteger('barcode_dpi')->default(203);
            $table->string('barcode_orientation', 20)->default('portrait');
            $table->string('barcode_currency', 20)->default('KD');
            $table->boolean('barcode_show_name')->default(true);
            $table->boolean('barcode_show_value')->default(true);
            $table->boolean('barcode_show_price')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('printer_settings', function (Blueprint $table) {
            $table->dropColumn([
                'barcode_print_enabled',
                'barcode_connection',
                'barcode_local_printer_name',
                'barcode_network_ip',
                'barcode_network_port',
                'barcode_printer_language',
                'barcode_label_width_mm',
                'barcode_label_height_mm',
                'barcode_label_gap_mm',
                'barcode_dpi',
                'barcode_orientation',
                'barcode_currency',
                'barcode_show_name',
                'barcode_show_value',
                'barcode_show_price',
            ]);
        });
    }
};
