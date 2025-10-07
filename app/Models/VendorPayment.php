<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorPayment extends Model
{
    protected $guarded = [];
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
    public function allocations()
    {
        return $this->hasMany(VendorPaymentAllocation::class);
    }
    public function journalEntries()
    {
        return $this->morphMany(JournalEntry::class, 'reference');
    }
}
