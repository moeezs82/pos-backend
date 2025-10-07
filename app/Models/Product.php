<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;
    
    protected $fillable = [
        'sku',
        'barcode',
        'name',
        'description',
        'vendor_id',
        'category_id',
        'brand_id',
        'price',
        'cost_price',
        'wholesale_price',
        'stock_qty',
        'reorder_level',
        'track_inventory',
        'tax_rate',
        'tax_inclusive',
        'discount',
        'is_active'
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function stocks()
    {
        return $this->hasMany(ProductStock::class);
    }

    public function journalEntries()
    {
        return $this->morphMany(JournalEntry::class, 'reference');
    }
}
