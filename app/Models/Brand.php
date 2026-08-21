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

    protected $fillable = [
        'name',
        'normalized_name',
        'description',
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

    protected $casts = [
        'status' => 'string',
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
