<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id',
        'brand_id',
        'sku',
        'upc',
        'status',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    protected $appends = ['display_name'];

    // ==================== RELATIONSHIPS ====================

    /**
     * Get the parent product
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the brand
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the pricing for this variant
     */
    public function pricing(): HasOne
    {
        return $this->hasOne(ProductPricing::class, 'variant_id');
    }

    /**
     * Get the inventory records for this variant
     */
    public function inventory(): HasMany
    {
        return $this->hasMany(ProductInventory::class, 'variant_id');
    }

    /**
     * Get the suppliers for this variant
     */
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'product_suppliers', 'variant_id', 'supplier_id')
            ->withPivot('supplier_cost', 'lead_time_days', 'minimum_order_quantity', 'is_preferred')
            ->withTimestamps();
    }

    /**
     * Get purchase order items
     */
    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'variant_id');
    }

    /**
     * Get specs/attributes specific to this variant.
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class, 'variant_id');
    }

    // ==================== ACCESSORS ====================

    /**
     * Get display name (Product Name - Brand)
     */
    public function getDisplayNameAttribute(): string
    {
        $productName = $this->product?->name ?? 'Producto';
        $brandName = $this->brand?->name ?? 'Sin marca';
        return "{$productName} - {$brandName}";
    }

    /**
     * Get total available stock across all warehouses
     */
    public function getTotalStockAttribute(): int
    {
        if ($this->relationLoaded('inventory')) {
            return $this->inventory->sum('quantity_available');
        }
        return $this->inventory()->sum('quantity_available');
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByBrand($query, $brandId)
    {
        return $query->where('brand_id', $brandId);
    }

    public function scopeWithStock($query)
    {
        return $query->whereHas('inventory', function ($q) {
            $q->where('quantity_available', '>', 0);
        });
    }
}
