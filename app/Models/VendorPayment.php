<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorPayment extends Model
{
    protected $guarded = [];
    protected $casts = [
        'paid_at' => 'date',
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];
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
    public function reversedBy()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
