<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryBoyReceived extends Model
{
    protected $fillable = [
        'user_id',
        'branch_id',
        'amount',
    ];
    protected $table = 'delivery_boy_received';
}
