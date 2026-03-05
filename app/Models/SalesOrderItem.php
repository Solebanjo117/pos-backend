<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_order_id',
        'product_id',
        'variant_id',
        'warehouse_id',
        'seller_id',
        'quantity',
        'unit_price',
        'unit_cost',
        'discount_percent',
        'discount_amount',
        'tax_percent',
        'tax_amount',
        'subtotal',
        'total',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    protected $appends = ['formatted_total', 'product_name', 'product_sku', 'warehouse_name', 'seller_name'];

    // ==================== RELATIONSHIPS ====================
    
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
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

    public function seller(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'seller_id');
    }

    // ==================== ACCESSORS ====================
    
    public function getFormattedTotalAttribute(): string
    {
        return '$' . number_format($this->total, 2);
    }

    public function getProductNameAttribute(): ?string
    {
        return $this->product?->name;
    }

    public function getProductSkuAttribute(): ?string
    {
        return $this->product?->sku_base;
    }

    public function getWarehouseNameAttribute(): ?string
    {
        return $this->warehouse?->name;
    }

    public function getSellerNameAttribute(): ?string
    {
        return $this->seller?->name;
    }

    /**
     * Get the gross profit for this line item.
     */
    public function getProfitAttribute(): float
    {
        $effectivePrice = (float) $this->unit_price * (1 - ((float) $this->discount_percent / 100));
        return ($effectivePrice - (float) $this->unit_cost) * $this->quantity;
    }

    // ==================== BUSINESS METHODS ====================
    
    /**
     * Calculate subtotal (quantity * unit_price - discount)
     */
    public function calculateSubtotal(): float
    {
        $subtotal = $this->quantity * $this->unit_price;
        $this->discount_amount = 0.0;
        
        if ($this->discount_percent > 0) {
            $this->discount_amount = $subtotal * ($this->discount_percent / 100);
        }
        
        $this->subtotal = $subtotal - $this->discount_amount;
        
        return (float) $this->subtotal;
    }

    /**
     * Calculate tax amount
     */
    public function calculateTax(): float
    {
        $this->tax_amount = 0.0;
        
        if ($this->tax_percent > 0) {
            $this->tax_amount = $this->subtotal * ($this->tax_percent / 100);
        }
        
        return (float) $this->tax_amount;
    }

    /**
     * Calculate total (subtotal + tax)
     */
    public function calculateTotal(): float
    {
        $this->calculateSubtotal();
        $this->calculateTax();
        $this->total = $this->subtotal + $this->tax_amount;
        
        return (float) $this->total;
    }

    /**
     * Boot method to auto-calculate totals
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($item) {
            $item->calculateTotal();
        });

        static::updating(function ($item) {
            // Only recalculate if relevant fields changed
            if ($item->isDirty(['quantity', 'unit_price', 'discount_percent', 'discount_amount', 'tax_percent'])) {
                $item->calculateTotal();
            }
        });

        static::saved(function ($item) {
            // Recalculate order totals when item is saved
            $item->salesOrder->calculateTotals();
        });

        static::deleted(function ($item) {
            // Recalculate order totals when item is deleted
            $item->salesOrder->calculateTotals();
        });
    }
}
