<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'variant_id',
        'quantity_ordered',
        'quantity_received',
        'unit_cost',
        'subtotal',
        'notes',
    ];

    protected $casts = [
        'purchase_order_id' => 'integer',
        'product_id' => 'integer',
        'quantity_ordered' => 'integer',
        'quantity_received' => 'integer',
        'unit_cost' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    protected $appends = ['pending_quantity', 'is_fully_received'];

    // ==================== RELATIONSHIPS ====================

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    // ==================== ACCESSORS ====================

    public function getPendingQuantityAttribute(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }

    public function getIsFullyReceivedAttribute(): bool
    {
        return $this->quantity_received >= $this->quantity_ordered;
    }

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        // Auto-calculate subtotal
        static::creating(function ($item) {
            $item->subtotal = $item->quantity_ordered * $item->unit_cost;
        });

        static::updating(function ($item) {
            $item->subtotal = $item->quantity_ordered * $item->unit_cost;
        });
    }
}
