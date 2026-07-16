<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class PaymentMethodAccount extends Model
{
    protected $fillable = [
        'method',
        'display_name',
        'account_id',
        'branch_id',
        'is_active',
        'affects_cash_drawer',
        'sort_order',
        'icon_key',
        'is_inherited',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'affects_cash_drawer' => 'boolean',
        'is_inherited'        => 'boolean',
        'sort_order'          => 'integer',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    /** Normalize an incoming method string to its immutable machine code. */
    public static function normalizeCode(?string $method): string
    {
        return strtolower(trim((string) $method));
    }

    public function scopeForBranch(Builder $q, ?int $branchId): Builder
    {
        return $branchId
            ? $q->where('branch_id', $branchId)
            : $q->whereNull('branch_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Presentation-friendly name, falling back to a titleized code. */
    public function getPresentationNameAttribute(): string
    {
        return $this->display_name
            ?: ucwords(str_replace(['_', '-'], ' ', (string) $this->method));
    }
}
