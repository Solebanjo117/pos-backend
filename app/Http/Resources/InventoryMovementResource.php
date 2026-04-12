<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'movement_type' => $this->movement_type,
            'product' => $this->whenLoaded('product', function () {
                return [
                    'id' => $this->product->id,
                    'name' => $this->product->name,
                    'sku' => $this->product->sku_base,
                ];
            }),
            'warehouse' => $this->whenLoaded('warehouse', function () {
                return [
                    'id' => $this->warehouse->id,
                    'name' => $this->warehouse->name,
                    'code' => $this->warehouse->code,
                ];
            }),
            'destination_warehouse' => $this->whenLoaded('destinationWarehouse', function () {
                return $this->destination_warehouse_id ? [
                    'id' => $this->destinationWarehouse->id,
                    'name' => $this->destinationWarehouse->name,
                    'code' => $this->destinationWarehouse->code,
                ] : null;
            }),
            'quantity' => $this->quantity,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'user' => $this->whenLoaded('user', function () {
                return $this->user_id ? [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ] : null;
            }),
            'supplier' => $this->whenLoaded('supplier', function () {
                return $this->supplier_id ? [
                    'id'   => $this->supplier->id,
                    'name' => $this->supplier->name,
                ] : null;
            }),
            'product_batch_id' => $this->product_batch_id,
            'product_batch' => $this->whenLoaded('productBatch', function () {
                return [
                    'id' => $this->productBatch->id,
                    'batch_number' => $this->productBatch->batch_number,
                    'expiry_date' => $this->productBatch->expiry_date?->toIso8601String(),
                ];
            }),
            'variant' => $this->whenLoaded('variant', function () {
                return $this->variant_id ? [
                    'id' => $this->variant->id,
                    'sku' => $this->variant->sku,
                    // attributes? need to check model
                ] : null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
