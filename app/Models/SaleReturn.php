<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleReturn extends Model
{
    protected $fillable = [
        'sale_id','customer_id','branch_id','return_no',
        'subtotal','tax','total','status','reason'
    ];

    public function items() {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function sale() {
        return $this->belongsTo(Sale::class);
    }

}
