<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $fillable = [
        'invoice_no',
        'vendor_id',
        'branch_id',
        'subtotal',
        'discount',
        'tax',
        'total',
        'status',
        'receive_status',
        'expected_at',
        'notes',
    ];

    protected $casts = [
        'expected_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }
    public function payments()
    {
        return $this->hasMany(PurchasePayment::class);
    }
}
