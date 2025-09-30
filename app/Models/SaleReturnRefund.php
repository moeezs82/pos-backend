<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleReturnRefund extends Model
{
    protected $fillable = [
        'sale_return_id',
        'amount',
        'method',
        'reference',
        'refunded_at',
        'cash_transaction_id',
        'created_by'
    ];
    protected $casts = ['refunded_at' => 'date'];

    public function saleReturn()
    {
        return $this->belongsTo(\App\Models\SaleReturn::class);
    }
    public function cashTransaction()
    {
        return $this->belongsTo(\App\Models\CashTransaction::class);
    }
}
