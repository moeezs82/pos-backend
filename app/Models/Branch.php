<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $guarded = [];

    public function stocks()
    {
        return $this->hasMany(ProductStock::class);
    }
}
