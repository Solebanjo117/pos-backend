<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sku_base',
        'product_line_id',
        'category_id',
        'name',
        'description',
        'image_url',
        'status',
        'batch_tracking',
        'is_universal',
    ];

    protected $casts = [
        'product_line_id' => 'integer',
        'status' => 'string',
        'batch_tracking' => 'boolean',
        'is_universal' => 'boolean',
    ];

    /**
     * Get the variants for this product.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Get the default variant for this product.
     */
    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    /**
     * Get the VINs linked to this product.
     */
    public function vins(): HasMany
    {
        return $this->hasMany(ProductVin::class);
    }

    /**
     * Get the brand via default variant (for backwards compatibility).
     */
    public function getBrandAttribute()
    {
        return $this->defaultVariant?->brand;
    }

    /**
     * Get the product line that owns the product.
     */
    public function productLine(): BelongsTo
    {
        return $this->belongsTo(ProductLine::class);
    }

    /**
     * Get the category for this product.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the pricing for the product.
     */
    public function pricing(): HasOne
    {
        return $this->hasOne(ProductPricing::class);
    }

    /**
     * Get the attributes for the product.
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class);
    }

    /**
     * Get the suppliers for the product.
     */
    public function productSuppliers(): HasMany
    {
        return $this->hasMany(ProductSupplier::class);
    }

    /**
     * Get the suppliers through the pivot table.
     */
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'product_suppliers')
            ->withPivot('supplier_cost', 'lead_time_days', 'minimum_order_quantity', 'is_preferred')
            ->withTimestamps();
    }

    /**
     * Get the media files for the product.
     */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderBy('display_order');
    }

    /**
     * Get the inventory records for the product.
     */
    public function inventory(): HasMany
    {
        return $this->hasMany(ProductInventory::class);
    }

    /**
     * Get the OEM references for the product.
     */
    public function oemReferences(): HasMany
    {
        return $this->hasMany(OemReference::class);
    }

    /**
     * Get the alternative products (where this is the primary).
     */
    public function alternatives(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'alternative_products', 'primary_product_id', 'alternative_product_id')
            ->withPivot('relationship_type')
            ->withTimestamps();
    }

    /**
     * Get the vehicle compatibility records.
     */
    public function vehicleCompatibility(): HasMany
    {
        return $this->hasMany(ProductVehicleCompatibility::class);
    }

    /**
     * Get the compatible vehicle engines.
     */
    public function compatibleEngines(): BelongsToMany
    {
        return $this->belongsToMany(VehicleEngine::class, 'product_vehicle_compatibility', 'product_id', 'engine_id')
            ->with('model.make')
            ->withPivot('notes')
            ->withTimestamps();
    }

    /**
     * Get products where this is listed as an alternative.
     */
    public function primaryProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'alternative_products', 'alternative_product_id', 'primary_product_id')
            ->withPivot('relationship_type')
            ->withTimestamps();
    }


    /**
     * Intelligent search scope with fuzzy multi-word matching and VIN detection.
     *
     * Splits the search term into individual words and requires ALL words to match
     * across product name, SKU, variant SKU/UPC, OEM codes, or attribute values.
     * If the term is exactly 17 characters, it is also treated as a potential VIN.
     */
    public function scopeSearch($query, ?string $term)
    {
        if (empty($term)) {
            return $query;
        }

        $term = trim($term);

        // Split into individual words for fuzzy cross-matching
        $words = array_filter(preg_split('/\s+/', $term));

        return $query->where(function ($q) use ($term, $words) {
            // --- Priority-based matching ---
            // 1. Exact SKU match (highest priority)
            // 2. Prefix match for short words (≤3 chars) to avoid false positives (e.g. "CS" matching "Tucson")
            // 3. Substring match for longer words
            $q->where(function ($fuzzy) use ($words) {
                foreach ($words as $word) {
                    // Short words (≤3 chars): use word-boundary matching (prefix/exact)
                    // Long words (>3 chars): use substring matching
                    if (mb_strlen($word) <= 3) {
                        $prefix = $word . '%';
                        $wordBoundary = '% ' . $word . '%';
                        $fuzzy->where(function ($inner) use ($word, $prefix, $wordBoundary) {
                            $inner->where('name', 'ilike', $prefix)
                                  ->orWhere('name', 'ilike', $wordBoundary)
                                  ->orWhere('sku_base', 'ilike', $prefix)
                                  ->orWhere('sku_base', 'ilike', $wordBoundary)
                                  ->orWhereHas('variants', function ($vq) use ($word, $prefix, $wordBoundary) {
                                      $vq->where('sku', 'ilike', $prefix)
                                         ->orWhere('sku', 'ilike', $wordBoundary)
                                         ->orWhere('upc', 'ilike', $prefix)
                                         ->orWhere('upc', 'ilike', $wordBoundary)
                                         ->orWhere('sku', $word)
                                         ->orWhere('upc', $word);
                                  })
                                  ->orWhereHas('oemReferences', function ($oq) use ($word, $prefix, $wordBoundary) {
                                      $oq->where('oem_code', $word)
                                         ->orWhere('oem_code', 'ilike', $prefix)
                                         ->orWhere('oem_code', 'ilike', $wordBoundary);
                                  })
                                  ->orWhereHas('attributes', function ($aq) use ($word, $prefix, $wordBoundary) {
                                      $aq->where('value', $word)
                                         ->orWhere('value', 'ilike', $prefix)
                                         ->orWhere('value', 'ilike', $wordBoundary);
                                  });
                        });
                    } else {
                        $w = '%' . $word . '%';
                        $fuzzy->where(function ($inner) use ($w) {
                            $inner->where('name', 'ilike', $w)
                                  ->orWhere('sku_base', 'ilike', $w)
                                  ->orWhereHas('variants', function ($vq) use ($w) {
                                      $vq->where('sku', 'ilike', $w)
                                         ->orWhere('upc', 'ilike', $w);
                                  })
                                  ->orWhereHas('oemReferences', function ($oq) use ($w) {
                                      $oq->where('oem_code', 'ilike', $w);
                                  })
                                  ->orWhereHas('attributes', function ($aq) use ($w) {
                                      $aq->where('value', 'ilike', $w);
                                  });
                        });
                    }
                }
            });

            // --- VIN detection (5+ alphanumeric characters) ---
            if (strlen($term) >= 5 && ctype_alnum($term)) {
                $q->orWhereHas('vins', function ($vq) use ($term) {
                    $vq->where('vin', strtoupper($term));
                });
            }
        });
    }

    /**
     * Scope to get only active products.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get discontinued products.
     */
    public function scopeDiscontinued($query)
    {
        return $query->where('status', 'discontinued');
    }

    /**
     * Get total stock across all warehouses.
     */
    public function getTotalStockAttribute(): int
    {
        if ($this->relationLoaded('inventory')) {
            return $this->inventory->sum('quantity_available');
        }

        // Fallback or if using variants strategy
        if ($this->relationLoaded('variants')) {
            return $this->variants->sum(function ($variant) {
                return $variant->relationLoaded('inventory') 
                    ? $variant->inventory->sum('quantity_available') 
                    : 0;
            });
        }

        return $this->inventory()->sum('quantity_available');
    }

    /**
     * Get the batches for the product.
     */
    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }
}
