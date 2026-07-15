<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit entry written every time a branch subscription is changed.
 *
 * Each row records: who changed it, what it was before, what it became, why,
 * and when.  Rows are never edited or deleted — this is an append-only log.
 *
 * @property int    $id
 * @property int    $branch_id
 * @property int|null $changed_by
 * @property string|null $old_status
 * @property string|null $new_status
 * @property \Carbon\Carbon|null $old_expires_at
 * @property \Carbon\Carbon|null $new_expires_at
 * @property \Carbon\Carbon|null $old_grace_until
 * @property \Carbon\Carbon|null $new_grace_until
 * @property string|null $action    e.g. 'create','renew','suspend','reactivate','set_expiry'
 * @property string|null $reason
 * @property array|null  $metadata
 */
class SubscriptionAudit extends Model
{
    // Append-only — never update rows.
    protected $fillable = [
        'branch_id',
        'changed_by',
        'old_status',
        'new_status',
        'old_expires_at',
        'new_expires_at',
        'old_grace_until',
        'new_grace_until',
        'action',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'old_expires_at'  => 'datetime',
            'new_expires_at'  => 'datetime',
            'old_grace_until' => 'datetime',
            'new_grace_until' => 'datetime',
            'metadata'        => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
