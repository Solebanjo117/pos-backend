<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesQuoteItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_quote_id',
        'product_id',
        'variant_id',
        'warehouse_id',
        'quantity',
        'unit_price',
        'discount_percent',
        'discount_amount',
        'tax_percent',
        'tax_amount',
        'subtotal',
        'total',
        'notes',
        'is_custom_line',
        'custom_description',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_percent' => 'integer',
        'tax_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
        'is_custom_line' => 'boolean',
    ];

    protected $appends = ['product_name', 'product_sku'];

    // ==================== RELATIONSHIPS ====================

    public function salesQuote(): BelongsTo
    {
        return $this->belongsTo(SalesQuote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    // ==================== ACCESSORS ====================

    public function getProductNameAttribute(): ?string
    {
        if ($this->is_custom_line) {
            return $this->custom_description ?: 'Articulo miscelaneo';
        }

        return $this->product?->name;
    }

    public function getProductSkuAttribute(): ?string
    {
        if ($this->is_custom_line) {
            return 'MISC';
        }

        return $this->product?->sku_base;
    }

    // ==================== CALCULATIONS ====================

    public function calculateSubtotal(): float
    {
        $grossSubtotal = round((float) $this->quantity * (float) $this->unit_price, 2);
        $hasExplicitDiscountAmount = (! $this->exists && array_key_exists('discount_amount', $this->attributes))
            || ($this->exists && $this->isDirty('discount_amount'));

        if ($hasExplicitDiscountAmount) {
            $this->discount_amount = min(
                $grossSubtotal,
                max(0, round((float) $this->discount_amount, 2)),
            );
            $this->discount_percent = $grossSubtotal > 0
                ? round(((float) $this->discount_amount / $grossSubtotal) * 100, 2)
                : 0;
        } else {
            $this->discount_amount = round(
                $grossSubtotal * (max(0, (float) $this->discount_percent) / 100),
                2,
            );
        }

        $this->subtotal = round($grossSubtotal - (float) $this->discount_amount, 2);

        return (float) $this->subtotal;
    }

    public function calculateTax(): float
    {
        $this->tax_amount = 0.0;
        if ($this->tax_percent > 0) {
            $this->tax_amount = round((float) $this->subtotal * ($this->tax_percent / 100), 2);
        }

        return (float) $this->tax_amount;
    }

    public function calculateTotal(): float
    {
        $this->calculateSubtotal();
        $this->calculateTax();
        $this->total = round((float) $this->subtotal + (float) $this->tax_amount, 2);

        return (float) $this->total;
    }

    public function setTaxPercentAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['tax_percent'] = null;

            return;
        }

        $this->attributes['tax_percent'] = is_numeric($value)
            ? (int) round((float) $value)
            : $value;
    }

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($item) {
            $item->calculateTotal();
        });

        static::updating(function ($item) {
            if ($item->isDirty(['quantity', 'unit_price', 'discount_percent', 'discount_amount', 'tax_percent'])) {
                $item->calculateTotal();
            }
        });

        static::saved(function ($item) {
            $item->salesQuote->calculateTotals();
        });

        static::deleted(function ($item) {
            $item->salesQuote->calculateTotals();
        });
    }
}
