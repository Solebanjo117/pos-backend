<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPricing extends Model
{
    use HasFactory;

    protected $table = 'product_pricing';

    protected $fillable = [
        'product_id',
        'variant_id',
        'base_price',
        'msrp',
        'cost',
        'margin_percentage',
        'tax_rate',
        'tax_type',
        'max_discount_percentage',
        'pricing_formula',
    ];

    protected $appends = [
        'calculated_price',
    ];

    /**
     * Calculates the final price by evaluating the formula.
     * If no formula is present, it returns the base_price.
     */
    public function getCalculatedPriceAttribute(): float
    {
        if (empty($this->pricing_formula)) {
            return (float) $this->base_price;
        }

        try {
            $service = app(\App\Services\PricingFormulaService::class);
            $result = $service->preview(
                $this->pricing_formula, 
                (float) $this->base_price, 
                (float) $this->tax_rate
            );
            return $result['success'] ? (float) $result['result'] : (float) $this->base_price;
        } catch (\Exception $e) {
            return (float) $this->base_price;
        }
    }

    protected $casts = [
        'product_id' => 'integer',
        'base_price' => 'decimal:2',
        'msrp' => 'decimal:2',
        'cost' => 'decimal:2',
        'margin_percentage' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_type' => 'string',
        'max_discount_percentage' => 'decimal:2',
    ];

    /**
     * Get the product that owns the pricing.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the variant that owns the pricing.
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * Calculate and update margin percentage.
     */
    public function calculateMargin(): void
    {
        if ($this->base_price > 0) {
            $this->margin_percentage = (($this->base_price - $this->cost) / $this->base_price) * 100;
        }
    }
}
