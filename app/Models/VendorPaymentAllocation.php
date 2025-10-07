<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorPaymentAllocation extends Model
{
    protected $guarded = [];
    public function payment()
    {
        return $this->belongsTo(VendorPayment::class, 'vendor_payment_id');
    }
    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }
}
