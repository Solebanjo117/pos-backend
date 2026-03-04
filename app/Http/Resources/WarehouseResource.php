<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'location' => $this->location,
            'capacity' => $this->capacity,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            // Statistics (if loaded)
            'total_products' => $this->whenNotNull($this->total_products),
            'total_units' => $this->whenNotNull($this->total_units),
            'total_value' => $this->whenNotNull($this->total_value),
            'low_stock_products' => $this->whenNotNull($this->low_stock_products),
            'out_of_stock_products' => $this->whenNotNull($this->out_of_stock_products),
            'utilization_percentage' => $this->when(
                $this->capacity && $this->capacity > 0 && isset($this->total_units),
                fn() => round(($this->total_units / $this->capacity) * 100, 2)
            ),
        ];
    }
}
