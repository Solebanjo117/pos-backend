<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'parent_id',
        'description',
        'default_attributes',
        'icon',
        'image_url',
        'priority',
        'default_margin_percent',
        'pricing_formula',
        'min_suggested_price',
        'upsale_path',
        'applies_itbm',
        'itbm_percent',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'priority' => 'integer',
        'default_margin_percent' => 'float',
        'min_suggested_price' => 'float',
        'applies_itbm' => 'boolean',
        'itbm_percent' => 'integer',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate slug from name if not provided
        static::creating(function ($category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    /**
     * Get the parent category.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Get the child categories.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('priority');
    }

    /**
     * Get all descendants recursively (children, grandchildren, etc.).
     */
    public function descendants()
    {
        return $this->children()->with('descendants');
    }

    /**
     * Get all products in this category.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(CategoryMedia::class)->orderBy('display_order');
    }

    /**
     * Check if this category is a root category (no parent).
     */
    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Check if this category has children.
     */
    public function hasChildren(): bool
    {
        return $this->children()->count() > 0;
    }

    public function setItbmPercentAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['itbm_percent'] = null;

            return;
        }

        $this->attributes['itbm_percent'] = is_numeric($value)
            ? (int) round((float) $value)
            : $value;
    }

    public function getImageUrlAttribute(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        return asset('storage/'.ltrim($value, '/'));
    }

    public function setDefaultAttributesAttribute(mixed $value): void
    {
        if (! is_array($value)) {
            $this->attributes['default_attributes'] = $value;

            return;
        }

        $this->attributes['default_attributes'] = json_encode(
            $this->normalizeDefaultAttributes($value),
            JSON_UNESCAPED_UNICODE
        );
    }

    public function getDefaultAttributesAttribute(mixed $value): array
    {
        if (is_array($value)) {
            return $this->normalizeDefaultAttributes($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return [];
        }

        return $this->normalizeDefaultAttributes($decoded);
    }

    /**
     * @param  array<int, mixed>  $attributes
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDefaultAttributes(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $name = trim((string) ($attribute['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $type = $attribute['type'] ?? 'string';
            if (! in_array($type, ['string', 'select'], true)) {
                $type = 'string';
            }

            $options = [];
            if ($type === 'select' && is_array($attribute['options'] ?? null)) {
                $options = array_values(array_filter(
                    array_map(function ($option): string {
                        if (! is_scalar($option)) {
                            return '';
                        }

                        return trim((string) $option);
                    }, $attribute['options']),
                    fn (string $option): bool => $option !== ''
                ));
            }

            $normalized[] = [
                'name' => $name,
                'required' => (bool) ($attribute['required'] ?? false),
                'type' => $type,
                'options' => $type === 'select' ? $options : null,
                'unit' => isset($attribute['unit']) ? (string) $attribute['unit'] : null,
                'value' => isset($attribute['value']) ? (string) $attribute['value'] : null,
                'default_value' => isset($attribute['default_value']) ? (string) $attribute['default_value'] : null,
            ];
        }

        return $normalized;
    }
}
