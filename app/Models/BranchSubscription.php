<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One-to-one subscription record per branch.
 *
 * The absence of a row means the branch has no expiry constraints (treated as
 * "active" by SubscriptionStatusService — existing branches keep working after
 * the migration without any manual intervention).
 *
 * Only the authenticated SaaS Owner may create or update these records.
 * Subscription columns are NOT on the branches table to avoid mass-assignment
 * exposure through the generic BranchController::update() endpoint.
 *
 * @property int         $id
 * @property int         $branch_id
 * @property string      $status         trial|active|grace_period|expired|suspended
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $grace_until
 * @property string|null $suspended_reason
 * @property string|null $notes
 * @property int|null    $managed_by     user_id of last changer
 */
class BranchSubscription extends Model
{
    protected $fillable = [
        'branch_id',
        'status',
        'started_at',
        'expires_at',
        'grace_until',
        'suspended_reason',
        'notes',
        'managed_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at'  => 'datetime',
            'expires_at'  => 'datetime',
            'grace_until' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function managedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'managed_by');
    }

    /** Valid status values. */
    public const STATUSES = ['trial', 'active', 'grace_period', 'expired', 'suspended'];
}
