<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalPosting extends Model
{
    protected $guarded = [];
    public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
    public function account()
    {
        return $this->belongsTo(Account::class);
    }
    public function party()
    {
        return $this->morphTo();
    }
}
