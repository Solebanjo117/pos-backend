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
            'system_id' => $this->system_id,
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
                function () {
                    $saleTaxRate = (float) ($this->pricing->sale_tax_rate ?? $this->pricing->tax_rate ?? 0);
                    $costTaxRate = (float) ($this->pricing->cost_tax_rate ?? $this->pricing->tax_rate ?? $saleTaxRate);

                    return [
                        'base_price' => (float) $this->pricing->base_price,
                        'calculated_price' => (float) $this->pricing->calculated_price,
                        'msrp' => (float) $this->pricing->msrp,
                        'cost' => (float) $this->pricing->cost_total,
                        'cost_base' => (float) $this->pricing->cost_base,
                        'cost_miscellaneous' => (float) $this->pricing->cost_miscellaneous,
                        'cost_total' => (float) $this->pricing->cost_total,
                        'tax_rate' => $saleTaxRate,
                        'sale_tax_rate' => $saleTaxRate,
                        'cost_tax_rate' => $costTaxRate,
                        'tax_type' => $this->pricing->tax_type ?? 'standard',
                        'max_discount_percentage' => (float) $this->pricing->max_discount_percentage,
                        'pricing_formula' => $this->pricing->pricing_formula,
                    ];
                }
            ),

            'product' => $this->when($this->relationLoaded('product') && $this->product, fn () => [
                'id' => $this->product->id,
                'sku_base' => $this->product->sku_base,
                'name' => $this->product->name,
                'status' => $this->product->status,
            ]),

            'inventory' => $this->when(
                $this->relationLoaded('inventory'),
                fn () => $this->inventory->map(fn ($inv) => [
                    'warehouse' => [
                        'id' => $inv->warehouse?->id,
                        'name' => $inv->warehouse?->name,
                        'code' => $inv->warehouse?->code,
                    ],
                    'quantity_available' => $inv->quantity_available,
                    'quantity_reserved' => $inv->quantity_reserved ?? 0,
                    'reorder_point' => $inv->reorder_point,
                    'fifo_cost_current' => app(\App\Services\InventoryFifoService::class)->currentFifoCost(
                        (int) $this->product_id,
                        (int) $this->id,
                        (int) $inv->warehouse_id
                    ),
                ])
            ),

            'suppliers' => $this->when(
                $this->relationLoaded('suppliers'),
                fn () => $this->suppliers->map(fn ($supplier) => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'supplier_cost' => (float) ($supplier->pivot->supplier_cost ?? 0),
                    'lead_time_days' => $supplier->pivot->lead_time_days,
                    'minimum_order_quantity' => $supplier->pivot->minimum_order_quantity,
                    'is_preferred' => (bool) ($supplier->pivot->is_preferred ?? false),
                ])->values()
            ),

            'total_stock' => $this->when(
                $this->relationLoaded('inventory'),
                fn () => $this->inventory->sum('quantity_available')
            ),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
