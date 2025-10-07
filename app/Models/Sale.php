<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'invoice_no',
        'customer_id',
        'branch_id',
        'vendor_id',
        'salesman_id',
        'created_by',
        'subtotal',
        'discount',
        'tax',
        'total',
        'status'
    ];

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
    public function salesman()
    {
        return $this->belongsTo(User::class, 'salesman_id');
    }

    // Accessors
    public function getPaidAmountAttribute()
    {
        return $this->payments()->sum('amount');
    }

    public function getBalanceAttribute()
    {
        return $this->total - $this->paid_amount;
    }

    public function journalEntries()
    {
        return $this->morphMany(JournalEntry::class, 'reference');
    }
}
