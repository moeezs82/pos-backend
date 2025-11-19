<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use  SoftDeletes, HasFactory;

    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone', 'password', 'status', 'meta', 'address'
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    protected $hidden = [
        'password',
    ];
}
