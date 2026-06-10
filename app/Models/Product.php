<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sku_base',
        'alternate_code',
        'pricing_formula',
        'product_line_id',
        'category_id',
        'engine_id', // R21: motor/engine as reusable entity
        'name',
        'description',
        'image_url',
        'status',
        'batch_tracking',
        'is_universal',
        'system_id',
    ];

    protected $casts = [
        'product_line_id' => 'integer',
        'category_id' => 'integer',
        'engine_id' => 'integer',
        'status' => 'string',
        'batch_tracking' => 'boolean',
        'is_universal' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (! empty($product->system_id)) {
                return;
            }

            $product->system_id = static::generateSystemId();
        });
    }

    /**
     * Generate a unique 8-digit product system ID when one is not provided.
     */
    private static function generateSystemId(): string
    {
        $row = DB::table('system_sequences')
            ->where('key', 'product_system_id')
            ->lockForUpdate()
            ->first();

        if (! $row) {
            $maxNumericId = DB::table('products')
                ->whereNotNull('system_id')
                ->pluck('system_id')
                ->filter(fn ($id) => preg_match('/^\d+$/', (string) $id))
                ->map(fn ($id) => (int) $id)
                ->max() ?? 0;

            $nextValue = $maxNumericId + 1;

            DB::table('system_sequences')->insert([
                'key' => 'product_system_id',
                'next_value' => $nextValue + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return str_pad($nextValue, 8, '0', STR_PAD_LEFT);
        }

        $nextValue = (int) $row->next_value;

        DB::table('system_sequences')
            ->where('key', 'product_system_id')
            ->update([
                'next_value' => $nextValue + 1,
                'updated_at' => now(),
            ]);

        return str_pad($nextValue, 8, '0', STR_PAD_LEFT);
    }

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
     * Get the secondary categories that enrich product naming and attributes.
     */
    public function extraCategories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_extra_categories')
            ->withTimestamps()
            ->orderBy('name');
    }

    /**
     * Get the engine/motor for this product (R21).
     * This is the product's own engine (e.g., replacement engine part),
     * separate from vehicle compatibility fitment.
     */
    public function engine(): BelongsTo
    {
        return $this->belongsTo(VehicleEngine::class, 'engine_id');
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
     * Get the compatible vehicle chassis.
     */
    public function compatibleChassis(): BelongsToMany
    {
        return $this->belongsToMany(VehicleChassis::class, 'product_vehicle_compatibility', 'product_id', 'chassis_id')
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
     * If the term is alphanumeric and has at least 5 characters, it is also treated as a potential VIN.
     */
    public function scopeSearch($query, ?string $term)
    {
        if (empty($term)) {
            return $query;
        }

        $term = trim($term);
        $operator = static::textSearchOperator();

        // Split into individual words for fuzzy cross-matching
        $words = array_filter(preg_split('/\s+/', $term));

        return $query->where(function ($q) use ($term, $words, $operator) {

            // For normal searches, EVERY word must match EITHER product info OR vehicle info
            $q->where(function ($main) use ($words, $operator) {
                foreach ($words as $word) {
                    $attrName = null;
                    $attrValue = null;
                    $attrNumeric = null;
                    $attrUnit = null;
                    $codeNeedle = static::normalizedCodeNeedle($word);

                    if (preg_match('/^([^:=]{1,80})\s*[:=]\s*(.{1,200})$/u', $word, $m)) {
                        $attrName = trim($m[1]);
                        $attrValue = trim($m[2]);
                        if (preg_match('/^(\d+(?:[.,]\d+)?)([a-zA-Z%]+)$/', $attrValue, $nv)) {
                            $attrNumeric = str_replace(',', '.', $nv[1]);
                            $attrUnit = strtolower($nv[2]);
                        } elseif (preg_match('/^(\d+(?:[.,]\d+)?)\s*([a-zA-Z%]+)$/', $attrValue, $nv2)) {
                            $attrNumeric = str_replace(',', '.', $nv2[1]);
                            $attrUnit = strtolower($nv2[2]);
                        }
                    } elseif (preg_match('/^(\d+(?:[.,]\d+)?)([a-zA-Z%]+)$/', $word, $nu)) {
                        $attrNumeric = str_replace(',', '.', $nu[1]);
                        $attrUnit = strtolower($nu[2]);
                    }

                    $main->where(function ($wordMatchQuery) use ($word, $attrName, $attrValue, $attrNumeric, $attrUnit, $codeNeedle, $operator) {

                        // 1. Matches Product Info
                        $wordMatchQuery->where(function ($productInfo) use ($word, $attrName, $attrValue, $attrNumeric, $attrUnit, $codeNeedle, $operator) {
                            if (mb_strlen($word) <= 3) {
                                $prefix = $word.'%';
                                $wordBoundary = '% '.$word.'%';
                                $productInfo->where('name', $operator, $prefix)
                                    ->orWhere('name', $operator, $wordBoundary)
                                    ->orWhere('sku_base', $operator, $prefix)
                                    ->orWhere('sku_base', $operator, $wordBoundary)
                                    ->orWhere('system_id', $operator, $prefix)
                                    ->orWhere('system_id', $operator, $wordBoundary)
                                    ->orWhere('alternate_code', $operator, $prefix)
                                    ->orWhere('alternate_code', $operator, $wordBoundary)
                                    ->orWhereHas('variants', function ($vq) use ($word, $prefix, $wordBoundary, $operator) {
                                        $vq->where('sku', $operator, $prefix)
                                            ->orWhere('sku', $operator, $wordBoundary)
                                            ->orWhere('upc', $operator, $prefix)
                                            ->orWhere('upc', $operator, $wordBoundary)
                                            ->orWhere('system_id', $operator, $prefix)
                                            ->orWhere('system_id', $operator, $wordBoundary)
                                            ->orWhere('sku', $word)
                                            ->orWhere('upc', $word)
                                            ->orWhere('system_id', $word);
                                    })
                                    ->orWhereHas('oemReferences', function ($oq) use ($word, $prefix, $wordBoundary, $operator) {
                                        $oq->where('oem_code', $word)
                                            ->orWhere('oem_code', $operator, $prefix)
                                            ->orWhere('oem_code', $operator, $wordBoundary);
                                    })
                                    ->orWhereHas('attributes', function ($aq) use ($word, $prefix, $wordBoundary, $operator) {
                                        $aq->whereNull('variant_id')
                                            ->where(function ($aqq) use ($word, $prefix, $wordBoundary, $operator) {
                                                $aqq->where('value', $word)
                                                    ->orWhere('value', $operator, $prefix)
                                                    ->orWhere('value', $operator, $wordBoundary)
                                                    ->orWhere('name', $operator, $prefix)
                                                    ->orWhere('name', $operator, $wordBoundary)
                                                    ->orWhere('unit', $operator, $prefix)
                                                    ->orWhere('unit', $operator, $wordBoundary);
                                            });
                                    })
                                    ->orWhereHas('category', function ($cq) use ($prefix, $wordBoundary, $operator) {
                                        $cq->where('name', $operator, $prefix)
                                            ->orWhere('name', $operator, $wordBoundary);
                                    })
                                    ->orWhereHas('productLine', function ($lq) use ($prefix, $wordBoundary, $operator) {
                                        $lq->where('name', $operator, $prefix)
                                            ->orWhere('name', $operator, $wordBoundary);
                                    })
                                    ->orWhereHas('variants.brand', function ($bq) use ($prefix, $wordBoundary, $operator) {
                                        $bq->where('name', $operator, $prefix)
                                            ->orWhere('name', $operator, $wordBoundary);
                                    })
                                    ->orWhereHas('vins', function ($vinQ) use ($word, $prefix, $wordBoundary, $operator) {
                                        $vinQ->where('vin', $word)
                                            ->orWhere('vin', $operator, $prefix)
                                            ->orWhere('vin', $operator, $wordBoundary);
                                    });
                            } else {
                                $w = '%'.$word.'%';
                                $productInfo->where('name', $operator, $w)
                                    ->orWhere('sku_base', $operator, $w)
                                    ->orWhere('system_id', $operator, $w)
                                    ->orWhere('alternate_code', $operator, $w)
                                    ->orWhereHas('variants', function ($vq) use ($w, $operator) {
                                        $vq->where('sku', $operator, $w)
                                            ->orWhere('upc', $operator, $w)
                                            ->orWhere('system_id', $operator, $w);
                                    })
                                    ->orWhereHas('oemReferences', function ($oq) use ($w, $operator) {
                                        $oq->where('oem_code', $operator, $w);
                                    })
                                    ->orWhereHas('attributes', function ($aq) use ($w, $operator) {
                                        $aq->whereNull('variant_id')
                                            ->where(function ($aqq) use ($w, $operator) {
                                                $aqq->where('value', $operator, $w)
                                                    ->orWhere('name', $operator, $w)
                                                    ->orWhere('unit', $operator, $w);
                                            });
                                    })
                                    ->orWhereHas('category', function ($cq) use ($w, $operator) {
                                        $cq->where('name', $operator, $w);
                                    })
                                    ->orWhereHas('productLine', function ($lq) use ($w, $operator) {
                                        $lq->where('name', $operator, $w);
                                    })
                                    ->orWhereHas('variants.brand', function ($bq) use ($w, $operator) {
                                        $bq->where('name', $operator, $w);
                                    })
                                    ->orWhereHas('vins', function ($vinQ) use ($w, $operator) {
                                        $vinQ->where('vin', $operator, $w);
                                    });
                            }

                            if ($codeNeedle) {
                                static::orWhereNormalizedCodes($productInfo, ['sku_base', 'system_id', 'alternate_code'], $codeNeedle);
                                $productInfo->orWhereHas('variants', function ($vq) use ($codeNeedle) {
                                    static::whereNormalizedCodes($vq, ['sku', 'upc', 'system_id'], $codeNeedle);
                                });
                                $productInfo->orWhereHas('oemReferences', function ($oq) use ($codeNeedle) {
                                    static::whereNormalizedCodes($oq, ['oem_code'], $codeNeedle);
                                });
                            }

                            if ($attrName && $attrValue) {
                                $nameLike = '%'.$attrName.'%';
                                $valueLike = '%'.$attrValue.'%';
                                $productInfo->orWhereHas('attributes', function ($aq) use ($nameLike, $valueLike, $attrNumeric, $attrUnit, $operator) {
                                    $aq->whereNull('variant_id')
                                        ->where('name', $operator, $nameLike)
                                        ->where(function ($v) use ($valueLike, $attrNumeric, $attrUnit, $operator) {
                                            $v->where('value', $operator, $valueLike);
                                            if ($attrNumeric && $attrUnit) {
                                                $numLike = '%'.$attrNumeric.'%';
                                                $numUnitLike = '%'.$attrNumeric.$attrUnit.'%';
                                                $numSpaceUnitLike = '%'.$attrNumeric.' '.$attrUnit.'%';
                                                $v->orWhere('value', $operator, $numUnitLike)
                                                    ->orWhere('value', $operator, $numSpaceUnitLike)
                                                    ->orWhere('value', $operator, $numLike);
                                            }
                                        });

                                    if ($attrUnit) {
                                        $unitLike = '%'.$attrUnit.'%';
                                        $aq->where(function ($u) use ($unitLike, $operator) {
                                            $u->where('unit', $operator, $unitLike)
                                                ->orWhere('value', $operator, $unitLike);
                                        });
                                    }
                                });
                            }

                            if ($attrNumeric && $attrUnit) {
                                $numLike = '%'.$attrNumeric.'%';
                                $numUnitLike = '%'.$attrNumeric.$attrUnit.'%';
                                $numSpaceUnitLike = '%'.$attrNumeric.' '.$attrUnit.'%';
                                $unitLike = '%'.$attrUnit.'%';

                                $productInfo->orWhereHas('attributes', function ($aq) use ($numLike, $numUnitLike, $numSpaceUnitLike, $unitLike, $operator) {
                                    $aq->whereNull('variant_id')
                                        ->where(function ($v) use ($numLike, $numUnitLike, $numSpaceUnitLike, $operator) {
                                            $v->where('value', $operator, $numUnitLike)
                                                ->orWhere('value', $operator, $numSpaceUnitLike)
                                                ->orWhere('value', $operator, $numLike);
                                        })->where(function ($u) use ($unitLike, $operator) {
                                            $u->where('unit', $operator, $unitLike)
                                                ->orWhere('value', $operator, $unitLike);
                                        });
                                });
                            }
                        });

                        // 2. OR Matches Vehicle Info
                        $w = '%'.$word.'%';
                        $wordMatchQuery->orWhereHas('compatibleEngines', function ($eq) use ($w, $operator) {
                            $eq->where('engine_code', $operator, $w)
                                ->orWhereHas('model', function ($mq) use ($w, $operator) {
                                    $mq->where('name', $operator, $w)
                                        ->orWhereHas('make', function ($mkq) use ($w, $operator) {
                                            $mkq->where('name', $operator, $w);
                                        });
                                });
                        });

                        // 2b. OR Matches Chassis Info
                        $wordMatchQuery->orWhereHas('compatibleChassis', function ($cq) use ($w, $operator) {
                            $cq->where('code', $operator, $w)
                                ->orWhere('name', $operator, $w)
                                ->orWhereHas('model', function ($mq) use ($w, $operator) {
                                    $mq->where('name', $operator, $w)
                                        ->orWhereHas('make', function ($mkq) use ($w, $operator) {
                                            $mkq->where('name', $operator, $w);
                                        });
                                });
                        });

                        // 3. OR Product is Universal and the word matches SOME vehicle in the database
                        // This allows universal products to appear when searching for a specific car (e.g. "filtro silverado")
                        $wordMatchQuery->orWhere(function ($uq) use ($w, $operator) {
                            $uq->where('is_universal', true)
                                ->where(function ($existsQuery) use ($w, $operator) {
                                    $existsQuery->whereExists(function ($q) use ($w, $operator) {
                                        $q->select(\Illuminate\Support\Facades\DB::raw(1))->from('vehicle_makes')->where('name', $operator, $w);
                                    })
                                        ->orWhereExists(function ($q) use ($w, $operator) {
                                            $q->select(\Illuminate\Support\Facades\DB::raw(1))->from('vehicle_models')->where('name', $operator, $w);
                                        })
                                        ->orWhereExists(function ($q) use ($w, $operator) {
                                            $q->select(\Illuminate\Support\Facades\DB::raw(1))->from('vehicle_engines')->where('engine_code', $operator, $w);
                                        });
                                });
                        });
                    });
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

    private static function textSearchOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'LIKE';
    }

    private static function normalizedCodeNeedle(string $word): ?string
    {
        $needle = mb_strtolower(str_replace('-', '', trim($word)));

        return $needle === '' ? null : $needle;
    }

    private static function orWhereNormalizedCodes($query, array $columns, string $needle): void
    {
        $query->orWhere(function ($codeQuery) use ($columns, $needle): void {
            static::whereNormalizedCodes($codeQuery, $columns, $needle);
        });
    }

    private static function whereNormalizedCodes($query, array $columns, string $needle): void
    {
        foreach (array_values($columns) as $index => $column) {
            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
            $query->{$method}("lower(replace({$column}, '-', '')) LIKE ?", ['%'.$needle.'%']);
        }
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
     * Scope to get vendible products (active and not archived).
     */
    public function scopeVendible($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Check if the product is vendible (active and not archived).
     */
    public function isVendible(): bool
    {
        return $this->status === 'active' && ! $this->trashed();
    }

    /**
     * Get inherited images from categories (primary + extra) by reference.
     * Returns array of ['url', 'source', 'source_category_id', 'category_name'].
     */
    public function getInheritedImagesAttribute(): array
    {
        $inherited = [];
        $seenUrls = [];
        $productImageUrl = $this->resolveImageUrl($this->image_url);

        $categories = collect();

        if ($this->category_id) {
            if ($this->relationLoaded('category')) {
                if ($this->category) {
                    $categories->push($this->category);
                }
            } else {
                $primaryCategory = $this->category()->with('media')->first(['id', 'name', 'image_url']);
                if ($primaryCategory) {
                    $categories->push($primaryCategory);
                }
            }
        }

        if ($this->relationLoaded('extraCategories')) {
            $categories = $categories->concat($this->extraCategories);
        } else {
            $categories = $categories->concat($this->extraCategories()->with('media')->get());
        }

        foreach ($categories as $cat) {
            $categoryMedia = $cat->relationLoaded('media')
                ? $cat->media
                : $cat->media()->where('media_type', 'image')->orderBy('display_order')->get();

            foreach ($categoryMedia as $media) {
                $categoryImageUrl = $media->url;

                if (empty($categoryImageUrl) || in_array($categoryImageUrl, $seenUrls, true)) {
                    continue;
                }

                if ($productImageUrl && $categoryImageUrl === $productImageUrl) {
                    continue;
                }

                $seenUrls[] = $categoryImageUrl;
                $inherited[] = [
                    'url' => $categoryImageUrl,
                    'source' => 'category',
                    'source_category_id' => $cat->id,
                    'category_name' => $cat->name,
                    'id' => null,
                    'category_media_id' => $media->id,
                    'display_order' => $media->display_order,
                ];
            }

            $categoryImageUrl = $cat->image_url;

            if (empty($categoryImageUrl) || in_array($categoryImageUrl, $seenUrls, true)) {
                continue;
            }

            if ($productImageUrl && $categoryImageUrl === $productImageUrl) {
                continue;
            }

            $seenUrls[] = $categoryImageUrl;
            $inherited[] = [
                'url' => $categoryImageUrl,
                'source' => 'category',
                'source_category_id' => $cat->id,
                'category_name' => $cat->name,
                'id' => null,
                'display_order' => count($inherited),
            ];
        }

        return $inherited;
    }

    /**
     * Get all images for the product: own image_url, media, and inherited category images.
     */
    public function getAllImagesAttribute(): array
    {
        $all = [];
        $mediaImages = [];

        $productImageUrl = $this->resolveImageUrl($this->image_url);
        if ($this->relationLoaded('media')) {
            foreach ($this->media->where('media_type', 'image') as $m) {
                $mediaImages[] = [
                    'id' => $m->id,
                    'url' => $m->url,
                    'source' => 'media',
                    'original_filename' => $m->original_filename,
                    'display_order' => $m->display_order,
                ];
            }
        }

        $mediaImageUrls = collect($mediaImages)->pluck('url')->all();
        if ($productImageUrl && ! in_array($productImageUrl, $mediaImageUrls, true)) {
            $all[] = [
                'id' => null,
                'url' => $productImageUrl,
                'source' => 'product',
                'original_filename' => 'Imagen principal',
                'display_order' => -1,
            ];
        }

        $seenUrls = collect($all)->pluck('url')->all();

        foreach ($mediaImages as $mediaImage) {
            if (in_array($mediaImage['url'], $seenUrls, true)) {
                continue;
            }

            $seenUrls[] = $mediaImage['url'];
            $all[] = $mediaImage;
        }

        foreach ($this->inherited_images as $img) {
            if (in_array($img['url'], $seenUrls, true)) {
                continue;
            }

            $seenUrls[] = $img['url'];
            $all[] = $img;
        }

        return $all;
    }

    private function resolveImageUrl(?string $imagePath): ?string
    {
        if ($imagePath === null || $imagePath === '') {
            return null;
        }

        if (Str::startsWith($imagePath, ['http://', 'https://'])) {
            return $imagePath;
        }

        return asset('storage/'.ltrim($imagePath, '/'));
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
