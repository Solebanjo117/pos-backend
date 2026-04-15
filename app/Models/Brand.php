<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'prefix',
        'description',
        'logo_url',
        'website',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    /**
     * Boot the model: auto-generate prefix and seed sequence on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (Brand $brand) {
            if (empty($brand->prefix)) {
                $name = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $brand->name));
                $prefix = substr($name, 0, 3);

                // Ensure uniqueness
                $basePrefix = $prefix;
                $suffix = 1;
                while (Brand::where('prefix', $prefix)->exists()) {
                    $prefix = substr($basePrefix, 0, 2) . $suffix;
                    $suffix++;
                }
                $brand->prefix = $prefix;
            }
        });

        static::created(function (Brand $brand) {
            \Illuminate\Support\Facades\DB::table('brand_sequences')->insertOrIgnore([
                'brand_id' => $brand->id,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

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
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
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
