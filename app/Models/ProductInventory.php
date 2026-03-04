<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductInventory extends Model
{
    use HasFactory;

    protected $table = 'product_inventory';

    protected $fillable = [
        'product_id',
        'variant_id',
        'warehouse_id',
        'quantity_available',
        'quantity_reserved',
        'reorder_point',
        'reorder_quantity',
        'location_in_warehouse',
        'last_restocked_at',
    ];

    protected $casts = [
        'quantity_available' => 'integer',
        'quantity_reserved' => 'integer',
        'reorder_point' => 'integer',
        'reorder_quantity' => 'integer',
        'last_restocked_at' => 'datetime',
    ];

    /**
     * Get the product that owns this inventory record
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the variant that owns this inventory record
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * Get the warehouse that owns this inventory record
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Check if product is low on stock
     */
    public function isLowStock(): bool
    {
        return $this->quantity_available <= $this->reorder_point && $this->quantity_available > 0;
    }

    /**
     * Check if product is out of stock
     */
    public function isOutOfStock(): bool
    {
        return $this->quantity_available === 0;
    }

    /**
     * Get total quantity (available + reserved)
     */
    public function getTotalQuantityAttribute(): int
    {
        return $this->quantity_available + $this->quantity_reserved;
    }
}
