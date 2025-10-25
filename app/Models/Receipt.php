<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    protected $guarded = [];
    public function allocations()
    {
        return $this->hasMany(ReceiptAllocation::class);
    }
    public function journalEntries()
    {
        return $this->morphMany(JournalEntry::class, 'reference');
    }
}
