<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    /**
     * Transform the variant resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'brand_id' => $this->brand_id,
            'sku' => $this->sku,
            'upc' => $this->upc,
            'is_default' => $this->is_default,
            
            'brand' => [
                'id' => $this->brand?->id,
                'name' => $this->brand?->name,
                'logo_url' => $this->brand?->logo_url ?? null,
            ],

            'status' => $this->status,
            
            'pricing' => $this->when(
                $this->relationLoaded('pricing') && $this->pricing,
                fn() => [
                    'base_price' => (float) $this->pricing->base_price,
                    'msrp' => (float) $this->pricing->msrp,
                    'cost' => (float) $this->pricing->cost,
                    'tax_rate' => (float) $this->pricing->tax_rate,
                    'tax_type' => $this->pricing->tax_type ?? 'standard',
                    'max_discount_percentage' => (float) $this->pricing->max_discount_percentage,
                ]
            ),
            
            'product' => $this->when($this->relationLoaded('product') && $this->product, fn() => [
                'id' => $this->product->id,
                'sku_base' => $this->product->sku_base,
                'name' => $this->product->name,
                'status' => $this->product->status,
            ]),
            
            'inventory' => $this->when(
                $this->relationLoaded('inventory'),
                fn() => $this->inventory->map(fn($inv) => [
                    'warehouse' => [
                        'id' => $inv->warehouse?->id,
                        'name' => $inv->warehouse?->name,
                        'code' => $inv->warehouse?->code,
                    ],
                    'quantity_available' => $inv->quantity_available,
                    'quantity_reserved' => $inv->quantity_reserved ?? 0,
                    'reorder_point' => $inv->reorder_point,
                ])
            ),
            
            'total_stock' => $this->when(
                $this->relationLoaded('inventory'),
                fn() => $this->inventory->sum('quantity_available')
            ),
            
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
