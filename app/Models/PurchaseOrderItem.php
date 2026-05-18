<?php

namespace App\Models;

use App\Helpers\TaxRateHelper;
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
        'tax_rate',
        'tax_amount',
        'misc_cost',
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
        'tax_rate' => 'integer',
        'tax_amount' => 'decimal:2',
        'misc_cost' => 'decimal:2',
    ];

    protected $appends = [
        'pending_quantity',
        'is_fully_received',
        'status_label',
        'total_units_ordered',
        'total_units_received',
        'effective_received_quantity',
        'pre_applied_quantity',
        'total',
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

    public function getTotalAttribute(): float
    {
        return round(
            (float) $this->subtotal + (float) $this->tax_amount + (float) ($this->misc_cost ?? 0),
            2
        );
    }

    /**
     * Get the effective supplier: from item if set, otherwise from PO header.
     */
    public function getEffectiveSupplierAttribute(): ?Supplier
    {
        return $this->supplier ?? $this->purchaseOrder?->supplier;
    }

    public function setTaxRateAttribute($value): void
    {
        $this->attributes['tax_rate'] = TaxRateHelper::resolve($value);
    }

    /**
     * Calculate line amounts. If tax_amount is set to null in the payload,
     * it will be auto-calculated from tax_rate (store default). If a numeric
     * value is explicitly provided, it is kept as-is (manual ITBM amount).
     * misc_cost defaults to 0 when not present.
     */
    private static function calculateLineAmounts(PurchaseOrderItem $item): void
    {
        $subtotal = (float) $item->quantity_ordered
            * (float) ($item->units_per_package ?? 1)
            * (float) $item->unit_cost;

        $item->subtotal = round($subtotal, 2);

        // Determine if tax_amount was provided as an explicit numeric value.
        // The service passes null when no manual amount is given, so null means auto-calculate.
        $rawTaxAmount = $item->attributes['tax_amount'] ?? null;

        if ($rawTaxAmount !== null && $rawTaxAmount !== '') {
            // Manual ITBM amount — keep it
            $item->tax_amount = round(max(0, (float) $rawTaxAmount), 2);
        } else {
            // Auto-calculate from tax_rate
            $item->tax_rate = $item->tax_rate ?? TaxRateHelper::defaultRate();
            $item->tax_amount = round($item->subtotal * ((float) $item->tax_rate / 100), 2);
        }

        // misc_cost defaults to 0 when not set
        if (! array_key_exists('misc_cost', $item->attributes)) {
            $item->misc_cost = 0;
        }
    }

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($item) {
            self::calculateLineAmounts($item);
        });

        static::updating(function ($item) {
            self::calculateLineAmounts($item);
        });
    }
}
