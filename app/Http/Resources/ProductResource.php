<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'sku_base' => $this->sku_base,
            'name' => $this->name,
            'status' => $this->status,
            'image_url' => $this->image_url ? url('storage/' . $this->image_url) : null,
            
            'pricing' => $this->when($this->relationLoaded('pricing'), [
                'base_price' => (float) $this->pricing?->base_price,
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
                fn() => ProductVariantResource::collection($this->variants)
            ),
            
            // Total variants count
            'variants_count' => $this->when(
                $this->relationLoaded('variants'),
                fn() => $this->variants->count()
            ),
            
            
            // Total stock: always compute from variants to stay consistent with per-variant totals shown in UI.
        // Previously used Product::inventory() which could disagree with the sum of variant stocks
        // when warehouse filters or eager-loading constraints differed between the two relations.
        'total_stock' => (function() {
            // Preferred: sum from each variant's loaded inventory
            if ($this->relationLoaded('variants')) {
                return $this->variants->sum(function($variant) {
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
            
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
