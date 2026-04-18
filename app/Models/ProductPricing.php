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
        'cost_base',
        'cost_miscellaneous',
        'margin_percentage',
        'cost_tax_rate',
        'sale_tax_rate',
        'tax_rate',
        'tax_type',
        'max_discount_percentage',
        'pricing_formula',
    ];

    protected $appends = [
        'calculated_price',
        'cost_total',
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
            // Evaluate formulas against cost (`costo`), not persisted base_price.
            // base_price may already be the computed output, and reusing it here doubles the formula.
            $formulaCost = $this->cost_total > 0
                ? (float) $this->cost_total
                : ($this->cost !== null ? (float) $this->cost : (float) $this->base_price);
            $result = $service->preview(
                $this->pricing_formula, 
                $formulaCost,
                $this->resolveSaleTaxRateForCalculation()
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
        'cost_base' => 'decimal:2',
        'cost_miscellaneous' => 'decimal:2',
        'margin_percentage' => 'decimal:2',
        'cost_tax_rate' => 'integer',
        'sale_tax_rate' => 'integer',
        'tax_rate' => 'integer',
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
            $effectiveCost = (float) $this->cost_total;
            $this->margin_percentage = (($this->base_price - $effectiveCost) / $this->base_price) * 100;
        }
    }

    public function setCostBaseAttribute($value): void
    {
        $this->attributes['cost_base'] = $this->normalizeMoneyValue($value);
    }

    public function setCostMiscellaneousAttribute($value): void
    {
        $normalized = $this->normalizeMoneyValue($value);
        $this->attributes['cost_miscellaneous'] = $normalized ?? 0;
    }

    public function getCostBaseAttribute($value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (isset($this->attributes['cost']) && is_numeric($this->attributes['cost'])) {
            return (float) $this->attributes['cost'];
        }

        return 0.0;
    }

    public function getCostMiscellaneousAttribute($value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }

        return (float) $value;
    }

    public function getCostTotalAttribute(): float
    {
        $rawCostBase = $this->attributes['cost_base'] ?? null;

        // Keep legacy rows (without explicit breakdown) using stored cost as total.
        if ($rawCostBase === null || $rawCostBase === '') {
            return (float) ($this->attributes['cost'] ?? 0);
        }

        $costBase = (float) $this->cost_base;
        $costTaxRate = (float) ($this->cost_tax_rate ?? 0);
        $costMiscellaneous = (float) $this->cost_miscellaneous;

        return (float) round($costBase * (1 + ($costTaxRate / 100)) + $costMiscellaneous, 2);
    }

    public function setTaxRateAttribute($value): void
    {
        $normalized = $this->normalizePercentValue($value);
        $this->attributes['tax_rate'] = $normalized;

        // Keep legacy alias synchronized with explicit sale tax.
        if (!array_key_exists('sale_tax_rate', $this->attributes) || $this->attributes['sale_tax_rate'] === null) {
            $this->attributes['sale_tax_rate'] = $normalized;
        }
    }

    public function setSaleTaxRateAttribute($value): void
    {
        $normalized = $this->normalizePercentValue($value);
        $this->attributes['sale_tax_rate'] = $normalized;

        // tax_rate remains as compatibility alias of sale_tax_rate.
        $this->attributes['tax_rate'] = $normalized;
    }

    public function setCostTaxRateAttribute($value): void
    {
        $this->attributes['cost_tax_rate'] = $this->normalizePercentValue($value);
    }

    public function getTaxRateAttribute($value): ?int
    {
        $saleTaxRate = $this->normalizePercentValue($this->attributes['sale_tax_rate'] ?? null);
        if ($saleTaxRate !== null) {
            return $saleTaxRate;
        }

        return $this->normalizePercentValue($value);
    }

    public function getSaleTaxRateAttribute($value): ?int
    {
        $saleTaxRate = $this->normalizePercentValue($value);
        if ($saleTaxRate !== null) {
            return $saleTaxRate;
        }

        return $this->normalizePercentValue($this->attributes['tax_rate'] ?? null);
    }

    public function getCostTaxRateAttribute($value): ?int
    {
        $costTaxRate = $this->normalizePercentValue($value);
        if ($costTaxRate !== null) {
            return $costTaxRate;
        }

        $saleTaxRate = $this->normalizePercentValue($this->attributes['sale_tax_rate'] ?? null);
        if ($saleTaxRate !== null) {
            return $saleTaxRate;
        }

        return $this->normalizePercentValue($this->attributes['tax_rate'] ?? null);
    }

    private function normalizePercentValue($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    private function normalizeMoneyValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function resolveSaleTaxRateForCalculation(): float
    {
        return (float) ($this->sale_tax_rate ?? $this->tax_rate ?? 0);
    }
}
