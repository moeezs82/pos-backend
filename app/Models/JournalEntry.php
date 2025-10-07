<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    protected $guarded = [];
    public function postings(){ return $this->hasMany(JournalPosting::class); }
    public function reference(){ return $this->morphTo(); }
}
