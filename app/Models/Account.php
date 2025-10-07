<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $guarded = [];
    public function type(){ return $this->belongsTo(AccountType::class,'account_type_id'); }
}
