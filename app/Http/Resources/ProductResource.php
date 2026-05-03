<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array (for listings).
     * Now includes variant information.
     */
    public function toArray(Request $request): array
    {
        // Get default variant or first variant
        $defaultVariant = $this->defaultVariant ?? $this->variants->first();

        return [
            'id' => $this->id,
            'system_id' => $this->system_id,
            'search_match_type' => $this->search_match_type ?? null,
            'search_match_system_id' => $this->search_match_system_id ?? null,
            'search_match_variant_id' => $this->search_match_variant_id ?? null,
            'sku_base' => $this->sku_base,
            'alternate_code' => $this->alternate_code,
            'pricing_formula' => $this->pricing_formula,
            'name' => $this->name,
            'status' => $this->status,
            'is_universal' => (bool) $this->is_universal,
            'category_id' => $this->category_id,
            'image_url' => $this->getImageUrl(),

            'pricing' => $this->when($this->relationLoaded('pricing'), [
                'base_price' => (float) $this->pricing?->base_price,
                'calculated_price' => (float) $this->pricing?->calculated_price,
                'msrp' => (float) $this->pricing?->msrp,
            ]),

            'product_line' => $this->when($this->productLine, [
                'id' => $this->productLine?->id,
                'name' => $this->productLine?->name,
            ]),

            'category' => $this->when($this->category, [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
            ]),

            'extra_category_ids' => $this->relationLoaded('extraCategories')
                ? $this->extraCategories->pluck('id')->values()
                : [],
            'extra_categories' => $this->relationLoaded('extraCategories')
                ? $this->extraCategories->map(fn ($category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                ])->values()
                : [],

            // Default variant info (for backwards compatibility)
            'default_variant' => $this->when($defaultVariant, [
                'id' => $defaultVariant?->id,
                'sku' => $defaultVariant?->sku,
                'upc' => $defaultVariant?->upc,
                'brand' => [
                    'id' => $defaultVariant?->brand?->id,
                    'name' => $defaultVariant?->brand?->name,
                ],
            ]),

            // All variants (for expandable list)
            'variants' => $this->when(
                $this->relationLoaded('variants'),
                fn () => ProductVariantResource::collection($this->variants)
            ),

            // Total variants count
            'variants_count' => $this->when(
                $this->relationLoaded('variants'),
                fn () => $this->variants->count()
            ),

            // Total stock: always compute from variants to stay consistent with per-variant totals shown in UI.
            // Previously used Product::inventory() which could disagree with the sum of variant stocks
            // when warehouse filters or eager-loading constraints differed between the two relations.
            'total_stock' => (function () {
                // Preferred: sum from each variant's loaded inventory
                if ($this->relationLoaded('variants')) {
                    return $this->variants->sum(function ($variant) {
                        return $variant->relationLoaded('inventory')
                            ? $variant->inventory->sum('quantity_available')
                            : 0;
                    });
                }

                // Fallback: if variants not loaded, try product-level inventory
                if ($this->relationLoaded('inventory') && $this->inventory->count() > 0) {
                    return $this->inventory->sum('quantity_available');
                }

                return 0;
            })(),

            // Inventory records (for checking stock levels in frontend)
            'inventory' => $this->whenLoaded('inventory', function () {
                return $this->inventory->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'warehouse_id' => $item->warehouse_id,
                        'variant_id' => $item->variant_id,
                        'quantity_available' => (float) $item->quantity_available,
                        'quantity_reserved' => (float) $item->quantity_reserved,
                    ];
                });
            }),

            'attributes' => $this->when(
                $this->relationLoaded('attributes'),
                fn () => $this->serializedAttributes()
            ),

            // Key attributes (top 4) for quick display in search results / grids
            'key_attributes' => (function () {
                $attrs = collect();
                if ($this->relationLoaded('attributes')) {
                    $attrs = $this->attributes->whereNull('variant_id')->take(4);
                }

                return $attrs->map(fn ($a) => [
                    'name' => $a->name,
                    'value' => $a->value,
                    'unit' => $a->unit,
                ])->values();
            })(),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializedAttributes(): array
    {
        $attributes = $this->attributes->whereNull('variant_id')->values();

        if ($attributes->isEmpty()) {
            return [];
        }

        $categoriesById = $this->categoryDefinitionsById($attributes);

        return $attributes->map(function ($attribute) use ($categoriesById) {
            $definition = $this->findCategoryAttributeDefinition(
                $categoriesById->get((int) ($attribute->source_category_id ?? 0)),
                (string) $attribute->name
            );

            return [
                'id' => $attribute->id,
                'name' => $attribute->name,
                'value' => $attribute->value,
                'unit' => $attribute->unit,
                'type' => $definition['type'] ?? 'string',
                'options' => $definition['options'] ?? null,
                'source' => $attribute->source ?? 'custom',
                'source_category_id' => $attribute->source_category_id,
            ];
        })->all();
    }

    private function categoryDefinitionsById(Collection $attributes): Collection
    {
        $sourceCategoryIds = $attributes
            ->where('source', 'category')
            ->pluck('source_category_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($sourceCategoryIds->isEmpty()) {
            return collect();
        }

        $categoriesById = collect();

        if ($this->relationLoaded('category') && $this->category) {
            $categoriesById->put((int) $this->category->id, $this->category);
        }

        if ($this->relationLoaded('extraCategories')) {
            foreach ($this->extraCategories as $extraCategory) {
                $categoriesById->put((int) $extraCategory->id, $extraCategory);
            }
        }

        $missingIds = $sourceCategoryIds
            ->reject(fn (int $categoryId) => $categoriesById->has($categoryId))
            ->values();

        if ($missingIds->isNotEmpty()) {
            Category::query()
                ->whereIn('id', $missingIds)
                ->get(['id', 'default_attributes'])
                ->each(fn (Category $category) => $categoriesById->put((int) $category->id, $category));
        }

        return $categoriesById;
    }

    /**
     * @return array{type: string, options: array<int, string>|null}|null
     */
    private function findCategoryAttributeDefinition(?Category $category, string $attributeName): ?array
    {
        if (! $category) {
            return null;
        }

        $targetName = mb_strtolower(trim($attributeName));
        if ($targetName === '') {
            return null;
        }

        foreach (($category->default_attributes ?? []) as $definition) {
            $definitionName = mb_strtolower(trim((string) ($definition['name'] ?? '')));
            if ($definitionName !== $targetName) {
                continue;
            }

            $type = $definition['type'] ?? 'string';
            if (! in_array($type, ['string', 'select'], true)) {
                $type = 'string';
            }

            return [
                'type' => $type,
                'options' => $type === 'select'
                    ? $this->normalizeDefinitionOptions($definition['options'] ?? null)
                    : null,
            ];
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeDefinitionOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return array_values(array_filter(
            array_map(function ($option): string {
                if (! is_scalar($option)) {
                    return '';
                }

                return trim((string) $option);
            }, $options),
            fn (string $option): bool => $option !== ''
        ));
    }

    /**
     * Get the product image URL, falling back to the first media file.
     */
    private function getImageUrl(): ?string
    {
        // 1. Legacy column on products table
        if ($this->image_url) {
            return url('storage/'.$this->image_url);
        }

        // 2. First image from product_media relation
        if ($this->relationLoaded('media') && $this->media->isNotEmpty()) {
            $firstMedia = $this->media->first();

            return $firstMedia->file_path ? url('storage/'.$firstMedia->file_path) : null;
        }

        // 3. Inherited category image by reference for product cards/search results
        if ($this->relationLoaded('category') && $this->category?->image_url) {
            return $this->category->image_url;
        }

        if ($this->relationLoaded('extraCategories')) {
            $extraCategoryImage = $this->extraCategories
                ->first(fn ($category) => filled($category->image_url));

            return $extraCategoryImage?->image_url;
        }

        return null;
    }
}
