<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = ['sale_id', 'amount', 'method', 'reference', 'received_by', 'received_on'];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function cashTransaction()
    {
        return $this->belongsTo(CashTransaction::class);
    }
}
