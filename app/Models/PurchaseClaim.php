<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseClaim extends Model
{
    protected $fillable = [
        'claim_no','purchase_id','vendor_id','branch_id','type','status',
        'subtotal','tax','total','reason','created_by','approved_by','rejected_by','closed_by',
        'approved_at','rejected_at','closed_at'
    ];

    public function purchase() {
        return $this->belongsTo(Purchase::class);
    }

    public function vendor() {
        return $this->belongsTo(Vendor::class);
    }

    public function branch() {
        return $this->belongsTo(Branch::class);
    }

    public function items() {
        return $this->hasMany(PurchaseClaimItem::class);
    }

    public function receipts() {
        return $this->hasMany(PurchaseClaimReceipt::class);
    }
}
