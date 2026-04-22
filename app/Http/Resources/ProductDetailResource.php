<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'image_url' => $this->image_url ? url('storage/'.$this->image_url) : null,

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
                fn () => $this->attributes->map(fn ($attr) => [
                    'name' => $attr->definition?->name,
                    'value' => $attr->definition?->data_type === 'string'
                        ? $attr->text_value
                        : $attr->numeric_value,
                    'unit' => $attr->definition?->unit,
                    'data_type' => $attr->definition?->data_type,
                ])
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

            'suppliers' => $this->when(
                $this->relationLoaded('productSuppliers'),
                fn () => $this->productSuppliers->map(fn ($ps) => [
                    'id' => $ps->supplier->id,
                    'name' => $ps->supplier->name,
                    'supplier_cost' => (float) $ps->supplier_cost,
                    'lead_time_days' => $ps->lead_time_days,
                    'minimum_order_quantity' => $ps->minimum_order_quantity,
                    'is_preferred' => (bool) $ps->is_preferred,
                ])
            ),

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
                    'oem_code' => $ref->oem_code,
                    'manufacturer' => $ref->manufacturer,
                ])
            ),

            'alternatives' => $this->when(
                $this->relationLoaded('alternatives'),
                fn () => $this->alternatives->map(fn ($alt) => [
                    'id' => $alt->id,
                    'sku' => $alt->sku,
                    'name' => $alt->name,
                    'relationship_type' => $alt->pivot->relationship_type,
                ])
            ),

            // Product images (photos from media)
            'images' => $this->when(
                $this->relationLoaded('media'),
                fn () => $this->media
                    ->where('media_type', 'image')
                    ->sortBy('display_order')
                    ->values()
                    ->map(fn ($m) => [
                        'id' => $m->id,
                        'url' => $m->url,
                        'original_filename' => $m->original_filename,
                        'display_order' => $m->display_order,
                    ])
            ),

            'compatibility' => $this->when(
                $this->relationLoaded('vehicleCompatibility'),
                fn () => $this->vehicleCompatibility->map(fn ($comp) => [
                    'id' => $comp->id,
                    'make' => $comp->engine?->model?->make?->name ?? ($comp->chassis?->model?->make?->name ?? null),
                    'model' => $comp->engine?->model?->name ?? ($comp->chassis?->model?->name ?? null),
                    'year_range' => $comp->engine?->model?->year_range ?? ($comp->chassis?->model?->year_range ?? null),
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
}
