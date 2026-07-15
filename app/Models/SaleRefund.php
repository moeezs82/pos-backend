<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleRefund extends Model
{
    protected $fillable = [
        'sale_id', 'register_shift_id', 'cash_transaction_id', 'amount',
        'method', 'reference', 'refunded_at', 'created_by',
    ];

    protected $casts = ['amount' => 'decimal:2', 'refunded_at' => 'datetime'];

    public function sale() { return $this->belongsTo(Sale::class); }
    public function registerShift() { return $this->belongsTo(RegisterShift::class); }
    public function cashTransaction() { return $this->belongsTo(CashTransaction::class); }
}
