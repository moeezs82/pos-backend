<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    protected $guarded = [];
    protected $casts = [
        'received_at' => 'date',
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function allocations()
    {
        return $this->hasMany(ReceiptAllocation::class);
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
