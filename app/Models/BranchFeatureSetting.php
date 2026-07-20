<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One-to-one with Branch. Missing row means all features enabled.
 *
 * Never read this model directly in controllers — always go through
 * BranchFeatureService so enforcement logic stays centralised.
 */
class BranchFeatureSetting extends Model
{
    protected $table = 'branch_feature_settings';

    protected $fillable = [
        'branch_id',
        'delivery_enabled',
        'sale_vendor_enabled',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'delivery_enabled'    => 'boolean',
            'sale_vendor_enabled' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
