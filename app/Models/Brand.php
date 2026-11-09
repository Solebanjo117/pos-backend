<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Brand extends Model
{
    use HasFactory;

    /** Gamas posibles de una marca: cada marca es una sola de las dos. */
    public const TIER_STANDARD = 'standard';

    public const TIER_PREMIUM = 'premium';

    public const TIERS = [self::TIER_STANDARD, self::TIER_PREMIUM];

    protected $fillable = [
        'name',
        'normalized_name',
        'description',
        'tier',
        'logo_url',
        'website',
        'status',
    ];

    protected static function booted(): void
    {
        static::saving(function (Brand $brand): void {
            $brand->normalized_name = self::normalizeName($brand->name);
        });
    }

    public static function normalizeName(string $name): string
    {
        return Str::lower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }

    protected $attributes = [
        'tier' => self::TIER_STANDARD,
    ];

    protected $casts = [
        'status' => 'string',
        'tier' => 'string',
    ];

    /**
     * Get the product lines for the brand.
     */
    public function productLines(): HasMany
    {
        return $this->hasMany(ProductLine::class);
    }

    /**
     * Get the products for the brand.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'product_variants',
            'brand_id',
            'product_id'
        )->whereNull('product_variants.deleted_at')->distinct();
    }

    /**
     * Get the product variants for the brand.
     */
    public function productVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Get suppliers associated with this brand.
     */
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class)->withTimestamps();
    }
}
