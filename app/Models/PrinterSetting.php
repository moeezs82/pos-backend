<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrinterSetting extends Model
{
    protected $fillable = [
        'branch_id',
        'shop_name',
        'shop_address',
        'shop_phone',
        'footer_lines',
        'active_connection',
        'network_ip',
        'network_port',
        'local_printer_name',
        'main_invoice_template',
        'kitchen_print_enabled',
        'kitchen_network_ip',
        'kitchen_network_port',
        'kitchen_local_printer_name',
        'kitchen_invoice_template',
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
        'updated_by',
    ];

    protected $casts = [
        'kitchen_print_enabled' => 'boolean',
        'network_port'          => 'integer',
        'kitchen_network_port'  => 'integer',
        'barcode_print_enabled' => 'boolean',
        'barcode_network_port'  => 'integer',
        'barcode_label_width_mm' => 'float',
        'barcode_label_height_mm' => 'float',
        'barcode_label_gap_mm'  => 'float',
        'barcode_dpi'           => 'integer',
        'barcode_show_name'     => 'boolean',
        'barcode_show_value'    => 'boolean',
        'barcode_show_price'    => 'boolean',
        'footer_lines'          => 'array',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The row a given branch should use: its own row if one exists,
     * otherwise the global (branch_id = null) default row, otherwise null.
     */
    public static function forBranch(?int $branchId): ?self
    {
        if ($branchId !== null) {
            $scoped = static::where('branch_id', $branchId)->first();
            if ($scoped) {
                return $scoped;
            }
        }

        return static::whereNull('branch_id')->first();
    }
}
