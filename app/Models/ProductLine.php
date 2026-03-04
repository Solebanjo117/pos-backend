<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'name',
        'description',
        'priority',
    ];

    protected $casts = [
        'brand_id' => 'integer',
        'priority' => 'integer',
    ];

    /**
     * Get the brand that owns the product line.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the products for the product line.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
