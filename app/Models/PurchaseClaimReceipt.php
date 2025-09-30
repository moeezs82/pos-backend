<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseClaimReceipt extends Model
{
    protected $fillable = [
        'purchase_claim_id',
        'amount',
        'method',
        'reference',
        'received_at',
        'cash_transaction_id',
        'created_by'
    ];
    protected $casts = ['received_at' => 'date'];

    public function purchaseClaim()
    {
        return $this->belongsTo(\App\Models\PurchaseClaim::class);
    }
    public function cashTransaction()
    {
        return $this->belongsTo(\App\Models\CashTransaction::class);
    }
}
