<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSupplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'variant_id',
        'supplier_id',
        'supplier_cost',
        'lead_time_days',
        'minimum_order_quantity',
        'is_preferred',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'variant_id' => 'integer',
        'supplier_id' => 'integer',
        'supplier_cost' => 'decimal:2',
        'lead_time_days' => 'integer',
        'minimum_order_quantity' => 'integer',
        'is_preferred' => 'boolean',
    ];

    /**
     * Get the product.
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
     * Get the supplier.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
