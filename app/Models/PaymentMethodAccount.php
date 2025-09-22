<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethodAccount extends Model
{
    protected $fillable = ['method', 'account_id', 'branch_id'];
    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
