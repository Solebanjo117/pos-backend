<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplyListItem extends Model
{
    protected $fillable = [
        'product_id',
        'variant_id',
        'supplier_id',
        'added_by',
        'quantity_needed',
        'stock_added_qty',
        'unit_cost',
        'tax_rate',
        'sale_price',
        'notes',
    ];

    protected $casts = [
        'quantity_needed' => 'decimal:2',
        'stock_added_qty' => 'integer',
        'unit_cost'       => 'decimal:2',
        'tax_rate'        => 'decimal:2',
        'sale_price'      => 'decimal:2',
    ];

    protected $appends = ['subtotal', 'tax_amount', 'total'];

    // ==================== RELATIONSHIPS ====================

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    // ==================== ACCESSORS ====================

    public function getSubtotalAttribute(): float
    {
        return round((float) $this->quantity_needed * (float) $this->unit_cost, 2);
    }

    public function getTaxAmountAttribute(): float
    {
        return round($this->subtotal * ((float) $this->tax_rate / 100), 2);
    }

    public function getTotalAttribute(): float
    {
        return round($this->subtotal + $this->tax_amount, 2);
    }
}
