<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'movement_type',
        'reason',
        'product_id',
        'variant_id',
        'warehouse_id',
        'destination_warehouse_id',
        'quantity',
        'reference_type',
        'reference_id',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'reference_id' => 'integer',
    ];

    /**
     * Get the product for this movement
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * Get the warehouse for this movement (origin or destination depending on type)
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Get the destination warehouse for transfers
     */
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /**
     * Get the user who created this movement
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if this is a transfer movement
     */
    public function isTransfer(): bool
    {
        return $this->movement_type === 'transfer';
    }

    /**
     * Check if this is an inbound movement
     */
    public function isInbound(): bool
    {
        return $this->movement_type === 'in';
    }

    /**
     * Check if this is an outbound movement
     */
    public function isOutbound(): bool
    {
        return $this->movement_type === 'out';
    }

    /**
     * Get the product batch for this movement
     */
    public function productBatch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }
}
