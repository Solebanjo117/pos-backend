<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'quantity_before',
        'quantity_after',
        'movement_group_uuid',
        'reference_type',
        'reference_id',
        'notes',
        'user_id',
        'supplier_id',
        'product_batch_id',
        'business_datetime',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'quantity_before' => 'integer',
        'quantity_after' => 'integer',
        'reference_id' => 'integer',
        'product_batch_id' => 'integer',
        'business_datetime' => 'datetime',
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
     * Get the supplier associated with this inbound movement
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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

    public function lotAllocations(): HasMany
    {
        return $this->hasMany(InventoryLotAllocation::class);
    }

    public function sourceLots(): HasMany
    {
        return $this->hasMany(InventoryLot::class, 'source_movement_id');
    }
}
