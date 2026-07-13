<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Register extends Model
{
    protected $fillable = ['branch_id', 'name', 'code', 'device_identifier', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
    public function branch() { return $this->belongsTo(Branch::class); }
    public function shifts() { return $this->hasMany(RegisterShift::class); }
}
