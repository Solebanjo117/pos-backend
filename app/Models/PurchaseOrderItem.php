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
        'supplier_id',
        'product_id',
        'variant_id',
        'quantity_ordered',
        'units_per_package',
        'quantity_received',
        'pre_stock_applied_qty',
        'unit_cost',
        'subtotal',
        'status',
        'notes',
    ];

    protected $casts = [
        'purchase_order_id' => 'integer',
        'supplier_id' => 'integer',
        'product_id' => 'integer',
        'quantity_ordered' => 'integer',
        'units_per_package' => 'integer',
        'quantity_received' => 'integer',
        'pre_stock_applied_qty' => 'integer',
        'unit_cost' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    protected $appends = [
        'pending_quantity',
        'is_fully_received',
        'status_label',
        'total_units_ordered',
        'total_units_received',
        'effective_received_quantity',
        'pre_applied_quantity',
    ];

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

    /**
     * Get the supplier for this item (for daily global POs).
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    // ==================== ACCESSORS ====================

    public function getPendingQuantityAttribute(): int
    {
        return max(0, (int) $this->quantity_ordered - (int) $this->effective_received_quantity);
    }

    public function getIsFullyReceivedAttribute(): bool
    {
        return $this->pending_quantity === 0;
    }

    public function getEffectiveReceivedQuantityAttribute(): int
    {
        $preApplied = max(0, (int) ($this->pre_stock_applied_qty ?? 0));
        $manualReceived = max(0, (int) $this->quantity_received);

        return min((int) $this->quantity_ordered, $manualReceived + $preApplied);
    }

    public function getPreAppliedQuantityAttribute(): int
    {
        return max(0, (int) ($this->pre_stock_applied_qty ?? 0));
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->is_fully_received) {
            return 'Recibido';
        }

        if ($this->quantity_received > 0) {
            return 'Parcial';
        }

        return 'Pendiente';
    }

    public function getTotalUnitsOrderedAttribute(): int
    {
        return $this->quantity_ordered * ($this->units_per_package ?? 1);
    }

    public function getTotalUnitsReceivedAttribute(): int
    {
        return $this->effective_received_quantity * ($this->units_per_package ?? 1);
    }

    /**
     * Get the effective supplier: from item if set, otherwise from PO header.
     */
    public function getEffectiveSupplierAttribute(): ?Supplier
    {
        return $this->supplier ?? $this->purchaseOrder?->supplier;
    }

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        // Auto-calculate subtotal
        static::creating(function ($item) {
            $item->subtotal = $item->quantity_ordered * ($item->units_per_package ?? 1) * $item->unit_cost;
        });

        static::updating(function ($item) {
            $item->subtotal = $item->quantity_ordered * ($item->units_per_package ?? 1) * $item->unit_cost;
        });
    }
}
