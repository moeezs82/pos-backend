<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $fillable = ['name', 'code', 'type', 'branch_id', 'is_active'];
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
    public function transactions()
    {
        return $this->hasMany(CashTransaction::class);
    }
}
