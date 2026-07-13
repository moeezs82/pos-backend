<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegisterShift extends Model
{
    protected $guarded = [];
    protected $casts = [
        'opened_at' => 'datetime', 'closed_at' => 'datetime',
        'opening_cash' => 'decimal:2', 'expected_cash' => 'decimal:2',
        'counted_cash' => 'decimal:2', 'variance' => 'decimal:2',
        'pending_sync_accepted' => 'boolean',
    ];
    public function register() { return $this->belongsTo(Register::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function cashier() { return $this->belongsTo(User::class, 'cashier_id'); }
    public function openedBy() { return $this->belongsTo(User::class, 'opened_by'); }
    public function closedBy() { return $this->belongsTo(User::class, 'closed_by'); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by'); }
    public function sales() { return $this->hasMany(Sale::class); }
    public function movements() { return $this->hasMany(ShiftCashMovement::class); }
    public function isOpen(): bool { return $this->status === 'open'; }
}
