<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $guarded = [];

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
    // public function payments()
    // {
    //     return $this->hasMany(PurchasePayment::class);
    // }
    public function payments()
    {
        return $this->hasMany(VendorPayment::class, 'purchase_id');
    }

    public function journalEntries()
    {
        return $this->morphMany(JournalEntry::class, 'reference');
    }
}
