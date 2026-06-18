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
        'active_connection',
        'network_ip',
        'network_port',
        'local_printer_name',
        'kitchen_print_enabled',
        'kitchen_network_ip',
        'kitchen_network_port',
        'kitchen_local_printer_name',
        'updated_by',
    ];

    protected $casts = [
        'kitchen_print_enabled' => 'boolean',
        'network_port'          => 'integer',
        'kitchen_network_port'  => 'integer',
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
