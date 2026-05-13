<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ProductDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array (for detailed view).
     * Updated to support variant-based data model.
     */
    public function toArray(Request $request): array
    {
        // Get default variant or first variant
        $defaultVariant = $this->defaultVariant ?? $this->variants?->first();
        $pricing = $this->relationLoaded('pricing') ? $this->pricing : null;
        $saleTaxRate = (float) ($pricing?->sale_tax_rate ?? $pricing?->tax_rate ?? 0);
        $costTaxRate = (float) ($pricing?->cost_tax_rate ?? $pricing?->tax_rate ?? $saleTaxRate);
        $effectiveImages = collect($this->all_images ?? []);
        $effectiveImageUrl = $effectiveImages->first()['url'] ?? null;

        return [
            'id' => $this->id,
            'system_id' => $this->system_id,
            'sku_base' => $this->sku_base,
            'alternate_code' => $this->alternate_code,
            'pricing_formula' => $this->pricing_formula,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'batch_tracking' => $this->batch_tracking,
            'is_universal' => (bool) $this->is_universal,
            'category_id' => $this->category_id,
            'product_line_id' => $this->product_line_id,
            'image_url' => $this->image_url ? url('storage/'.$this->image_url) : $effectiveImageUrl,

            'pricing' => $this->when($this->relationLoaded('pricing') && $this->pricing, [
                'base_price' => (float) $this->pricing?->base_price,
                'calculated_price' => (float) $this->pricing?->calculated_price,
                'msrp' => (float) $this->pricing?->msrp,
                'cost' => (float) $this->pricing?->cost_total,
                'cost_base' => (float) $this->pricing?->cost_base,
                'cost_miscellaneous' => (float) $this->pricing?->cost_miscellaneous,
                'cost_total' => (float) $this->pricing?->cost_total,
                'margin_percentage' => (float) $this->pricing?->margin_percentage,
                'tax_rate' => $saleTaxRate,
                'sale_tax_rate' => $saleTaxRate,
                'cost_tax_rate' => $costTaxRate,
                'tax_type' => $this->pricing?->tax_type ?? 'standard',
                'max_discount_percentage' => (float) $this->pricing?->max_discount_percentage,
            ]),

            'product_line' => $this->when($this->productLine, [
                'id' => $this->productLine?->id,
                'name' => $this->productLine?->name,
                'description' => $this->productLine?->description,
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

            // Default variant info (for edit forms and backwards compatibility)
            'default_variant' => $this->when($defaultVariant, function () use ($defaultVariant) {
                return [
                    'id' => $defaultVariant->id,
                    'sku' => $defaultVariant->sku,
                    'upc' => $defaultVariant->upc,
                    'is_default' => $defaultVariant->is_default,
                    'brand' => [
                        'id' => $defaultVariant->brand?->id,
                        'name' => $defaultVariant->brand?->name,
                        'logo_url' => $defaultVariant->brand?->logo_url ?? null,
                    ],
                ];
            }),

            // All variants
            'variants' => $this->when(
                $this->relationLoaded('variants'),
                fn () => ProductVariantResource::collection($this->variants)
            ),

            'variants_count' => $this->when(
                $this->relationLoaded('variants'),
                fn () => $this->variants->count()
            ),

            'attributes' => $this->when(
                $this->relationLoaded('attributes'),
                fn () => $this->serializedAttributes()
            ),

            // Total stock across all variants
            'total_stock' => $this->when(
                $this->relationLoaded('variants'),
                function () {
                    return $this->variants->sum(function ($variant) {
                        if ($variant->relationLoaded('inventory')) {
                            return $variant->inventory->sum('quantity_available');
                        }

                        return 0;
                    });
                }
            ),

            'suppliers' => $this->variantSuppliers(),

            'media' => $this->when(
                $this->relationLoaded('media'),
                fn () => $this->media->map(fn ($media) => [
                    'id' => $media->id,
                    'media_type' => $media->media_type,
                    'category' => $media->category,
                    'filename' => $media->original_filename,
                    'url' => $media->url,
                    'file_size' => $media->formatted_size,
                    'display_order' => $media->display_order,
                ])
            ),

            'oem_references' => $this->when(
                $this->relationLoaded('oemReferences'),
                fn () => $this->oemReferences->map(fn ($ref) => [
                    'id' => $ref->id,
                    'oem_code' => $ref->oem_code,
                    'manufacturer' => $ref->manufacturer,
                ])
            ),

            'alternatives' => $this->when(
                $this->relationLoaded('alternatives'),
                fn () => $this->alternatives->map(fn ($alt) => [
                    'id' => $alt->id,
                    'sku' => $alt->sku_base,
                    'name' => $alt->name,
                    'relationship_type' => $alt->pivot->relationship_type,
                ])
            ),

            // Effective product gallery: own images plus inherited category images by reference.
            'images' => $effectiveImages
                ->values()
                ->map(fn (array $image, int $index) => [
                    'id' => $image['id'] ?? null,
                    'url' => $image['url'],
                    'original_filename' => $image['original_filename']
                        ?? $image['category_name']
                        ?? 'Imagen',
                    'display_order' => $image['display_order'] ?? $index,
                    'source' => $image['source'] ?? 'media',
                    'source_category_id' => $image['source_category_id'] ?? null,
                    'category_name' => $image['category_name'] ?? null,
                    'category_media_id' => $image['category_media_id'] ?? null,
                ]),

            'inherited_images' => $this->inherited_images ?? [],
            'all_images' => $this->all_images ?? [],

            'compatibility' => $this->when(
                $this->relationLoaded('vehicleCompatibility'),
                fn () => $this->vehicleCompatibility->map(fn ($comp) => [
                    'id' => $comp->id,
                    'scope_type' => $comp->scope_type,
                    'make_id' => $comp->make_id,
                    'model_id' => $comp->model_id,
                    'engine_id' => $comp->engine_id,
                    'chassis_id' => $comp->chassis_id,
                    'make' => $comp->make?->name ?? $comp->model?->make?->name ?? $comp->engine?->model?->make?->name ?? ($comp->chassis?->model?->make?->name ?? null),
                    'model' => $comp->model?->name ?? $comp->engine?->model?->name ?? ($comp->chassis?->model?->name ?? null),
                    'year_range' => $comp->model?->year_range ?? $comp->engine?->model?->year_range ?? ($comp->chassis?->model?->year_range ?? null),
                    'engine' => $comp->engine?->full_description,
                    'chassis' => $comp->chassis ? [
                        'id' => $comp->chassis->id,
                        'code' => $comp->chassis->code,
                        'name' => $comp->chassis->name,
                        'full_description' => $comp->chassis->full_description,
                    ] : null,
                    'all_engines' => (bool) $comp->all_engines,
                    'all_chassis' => (bool) $comp->all_chassis,
                    'notes' => $comp->notes,
                ])
            ),

            'inventory' => $this->when(
                $this->relationLoaded('variants'),
                function () {
                    return $this->variants->flatMap(function ($variant) {
                        if (! $variant->relationLoaded('inventory')) {
                            return [];
                        }

                        return $variant->inventory->map(function ($inv) use ($variant) {
                            return [
                                'id' => $inv->id,
                                'warehouse_id' => $inv->warehouse_id,
                                'warehouse' => [
                                    'id' => $inv->warehouse->id,
                                    'name' => $inv->warehouse->name,
                                    'code' => $inv->warehouse->code,
                                ],
                                'variant_id' => $variant->id,
                                'variant_sku' => $variant->sku,
                                'stock_available' => (int) $inv->quantity_available,
                                'stock_reserved' => (int) $inv->quantity_reserved,
                                'stock_on_order' => 0, // Placeholder as column missing in Model
                                'reorder_point' => (int) $inv->reorder_point,
                                'needs_reorder' => $inv->quantity_available <= $inv->reorder_point,
                            ];
                        });
                    })->values();
                }
            ),

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

            $type = $definition['type'] ?? 'string';

            return [
                'id' => $attribute->id,
                'name' => $attribute->name,
                'value' => $this->deserializeAttributeValue($attribute->value, $type),
                'unit' => $attribute->unit,
                'type' => $type,
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
     * Deserialize a stored attribute value.
     *
     * For select-type attributes the DB stores a JSON-encoded array.
     * Legacy values (plain strings) are wrapped in an array.
     *
     * @return string|string[]
     */
    private function deserializeAttributeValue(string $rawValue, string $type): string|array
    {
        if ($type !== 'select') {
            return $rawValue;
        }

        $decoded = json_decode($rawValue, true);

        if (is_array($decoded)) {
            return array_values(array_filter(
                array_map(fn ($v) => trim((string) $v), $decoded),
                fn (string $v) => $v !== ''
            ));
        }

        // Legacy: plain string stored for a select attribute—wrap in array.
        $trimmed = trim($rawValue);

        return $trimmed !== '' ? [$trimmed] : [];
    }

    private function variantSuppliers(): mixed
    {
        if ($this->relationLoaded('variants')) {
            $suppliers = $this->variants
                ->filter(fn ($variant) => $variant->relationLoaded('suppliers'))
                ->flatMap(fn ($variant) => $variant->suppliers->map(fn ($supplier) => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'variant_id' => $variant->id,
                    'variant_sku' => $variant->sku,
                    'brand_name' => $variant->brand?->name,
                    'supplier_cost' => (float) ($supplier->pivot->supplier_cost ?? 0),
                    'lead_time_days' => $supplier->pivot->lead_time_days,
                    'minimum_order_quantity' => $supplier->pivot->minimum_order_quantity,
                    'is_preferred' => (bool) ($supplier->pivot->is_preferred ?? false),
                ]));

            if ($suppliers->isNotEmpty()) {
                return $suppliers->values();
            }
        }

        return $this->when(
            $this->relationLoaded('productSuppliers'),
            fn () => $this->productSuppliers->map(fn ($ps) => [
                'id' => $ps->supplier->id,
                'name' => $ps->supplier->name,
                'variant_id' => $ps->variant_id,
                'variant_sku' => $ps->variant?->sku,
                'brand_name' => $ps->variant?->brand?->name,
                'supplier_cost' => (float) $ps->supplier_cost,
                'lead_time_days' => $ps->lead_time_days,
                'minimum_order_quantity' => $ps->minimum_order_quantity,
                'is_preferred' => (bool) $ps->is_preferred,
            ])->values()
        );
    }
}
