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
            'quantity_before' => $this->quantity_before,
            'quantity_after' => $this->quantity_after,
            'movement_group_uuid' => $this->movement_group_uuid,
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
                    'id' => $this->supplier->id,
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
            'lot_allocations' => $this->whenLoaded('lotAllocations', fn () => $this->serializedLotAllocations()),
            'fifo_allocations' => $this->whenLoaded('lotAllocations', fn () => $this->serializedLotAllocations()),
            'source_lots' => $this->whenLoaded('sourceLots', fn () => $this->serializedSourceLots()),
            'fifo_lots' => $this->whenLoaded('sourceLots', fn () => $this->serializedSourceLots()),
            'variant' => $this->whenLoaded('variant', function () {
                return $this->variant_id ? [
                    'id' => $this->variant->id,
                    'sku' => $this->variant->sku,
                    // attributes? need to check model
                ] : null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'business_datetime' => $this->business_datetime?->toIso8601String(),
        ];
    }

    private function serializedLotAllocations(): mixed
    {
        return $this->lotAllocations->map(fn ($allocation) => [
            'lot_id' => $allocation->inventory_lot_id,
            'quantity' => (int) $allocation->quantity,
            'unit_cost' => (float) $allocation->unit_cost,
            'total_cost' => (float) $allocation->total_cost,
            'sale_price' => (float) ($allocation->lot?->sale_price ?? 0),
            'source_type' => $allocation->lot?->source_type,
            'source_id' => $allocation->lot?->source_id,
            'received_at' => $allocation->lot?->received_at?->toIso8601String(),
        ])->values();
    }

    private function serializedSourceLots(): mixed
    {
        return $this->sourceLots->map(fn ($lot) => [
            'id' => $lot->id,
            'batch_number' => $lot->batch_number,
            'source_type' => $lot->source_type,
            'source_id' => $lot->source_id,
            'received_quantity' => (int) $lot->received_quantity,
            'quantity_available' => (int) $lot->quantity_available,
            'unit_cost_base' => (float) $lot->unit_cost_base,
            'tax_per_unit' => (float) $lot->tax_per_unit,
            'misc_per_unit' => (float) $lot->misc_per_unit,
            'landed_unit_cost' => (float) $lot->landed_unit_cost,
            'sale_price' => (float) $lot->sale_price,
            'received_at' => $lot->received_at?->toIso8601String(),
        ])->values();
    }
}
