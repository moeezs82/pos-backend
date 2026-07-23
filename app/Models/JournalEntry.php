<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    protected $fillable = [
        'entry_date',
        'memo',
        'branch_id',
        'reference_type',
        'reference_id',
        'created_by',
    ];

    protected $casts = [
        // Normalise to a pure date so Carbon comparisons and serialization stay
        // consistent regardless of whether a datetime or date string was stored.
        'entry_date' => 'date:Y-m-d',
    ];

    public function postings()
    {
        return $this->hasMany(JournalPosting::class);
    }

    public function reference()
    {
        return $this->morphTo();
    }
}
