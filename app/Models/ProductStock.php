<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductStock extends Model
{
    protected $guarded = [];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // 🔹 Each stock record belongs to one branch
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
