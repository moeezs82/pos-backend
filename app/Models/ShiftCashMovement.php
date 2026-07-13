<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftCashMovement extends Model
{
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'occurred_at' => 'datetime'];
    public function shift() { return $this->belongsTo(RegisterShift::class, 'register_shift_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
}
