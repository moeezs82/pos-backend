<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchasePayment extends Model
{
    protected $fillable = ['purchase_id', 'method', 'amount', 'tx_ref', 'paid_at', 'meta'];

    protected $casts = [
        'paid_at' => 'datetime',
        'meta'    => 'array',
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function cashTransaction()
    {
        return $this->belongsTo(CashTransaction::class);
    }
}
