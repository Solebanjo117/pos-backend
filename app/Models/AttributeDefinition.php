<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'data_type',
        'unit',
        'applicable_categories',
        'is_required',
    ];

    protected $casts = [
        'data_type' => 'string',
        'applicable_categories' => 'array',
        'is_required' => 'boolean',
    ];

    /**
     * Get the product attributes that use this definition.
     */
    public function productAttributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class);
    }
}
