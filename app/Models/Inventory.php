<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    use HasFactory;

    protected $table = 'inventory';

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'stock_available',
        'stock_reserved',
        'stock_on_order',
        'reorder_point',
        'target_level',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
        'stock_available' => 'integer',
        'stock_reserved' => 'integer',
        'stock_on_order' => 'integer',
        'reorder_point' => 'integer',
        'target_level' => 'integer',
    ];

    /**
     * Get the product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the warehouse.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Check if stock needs reordering.
     */
    public function needsReorder(): bool
    {
        return $this->stock_available <= $this->reorder_point;
    }

    /**
     * Get total committed stock.
     */
    public function getTotalCommittedAttribute(): int
    {
        return $this->stock_available + $this->stock_on_order;
    }
}
