<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseClaimItem extends Model
{
    protected $fillable = [
        'purchase_claim_id','purchase_item_id','product_id',
        'quantity','price','total','affects_stock','batch_no','expiry_date','remarks'
    ];

    public function claim() {
        return $this->belongsTo(PurchaseClaim::class, 'purchase_claim_id');
    }

    public function product() {
        return $this->belongsTo(Product::class);
    }

    public function purchaseItem() {
        return $this->belongsTo(PurchaseItem::class);
    }
}
