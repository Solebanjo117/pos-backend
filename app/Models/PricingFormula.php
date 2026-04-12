<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingFormula extends Model
{
    protected $fillable = [
        'name',
        'formula',
        'description',
        'is_preset',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_preset'  => 'boolean',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Scope active formulas ordered by sort_order.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
