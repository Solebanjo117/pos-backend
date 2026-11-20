<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryLot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchInventoryController extends Controller
{
    /**
     * Lotes FIFO abiertos (con existencia). Es la lista de "Lotes" de Inventario.
     * GET /api/v1/inventory/batches
     */
    public function index(Request $request): JsonResponse
    {
        return $this->openLots($request);
    }

    private function openLots(Request $request): JsonResponse
    {
        $query = InventoryLot::query()
            ->with(['product', 'variant.brand', 'warehouse'])
            ->where('quantity_available', '>', 0);

        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($query) use ($search): void {
                $query->where('batch_number', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($productQuery) use ($search): void {
                        $productQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('sku_base', 'like', "%{$search}%");
                    })
                    ->orWhereHas('variant', function ($variantQuery) use ($search): void {
                        $variantQuery->where('sku', 'like', "%{$search}%")
                            ->orWhereHas('brand', fn ($brandQuery) => $brandQuery->where('name', 'like', "%{$search}%"));
                    });
            });
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $lots = $query
            ->orderBy('received_at')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $lots->map(fn (InventoryLot $lot): array => [
                'id' => $lot->id,
                'product_id' => $lot->product_id,
                'variant_id' => $lot->variant_id,
                'warehouse_id' => $lot->warehouse_id,
                'product_name' => $lot->product?->name,
                'product_sku' => $lot->product?->sku_base,
                'variant_sku' => $lot->variant?->sku,
                'brand' => $lot->variant?->brand?->name ?? 'N/A',
                'warehouse_name' => $lot->warehouse?->name,
                'source_type' => $lot->source_type,
                'source_id' => $lot->source_id,
                'source_movement_id' => $lot->source_movement_id,
                'batch_number' => $lot->batch_number,
                'received_quantity' => (int) $lot->received_quantity,
                'quantity_available' => (int) $lot->quantity_available,
                'unit_cost_base' => round((float) $lot->unit_cost_base, 2),
                'tax_per_unit' => round((float) $lot->tax_per_unit, 2),
                'misc_per_unit' => round((float) $lot->misc_per_unit, 2),
                'landed_unit_cost' => round((float) $lot->landed_unit_cost, 2),
                'sale_price' => round((float) $lot->sale_price, 2),
                'remaining_value' => round((int) $lot->quantity_available * (float) $lot->landed_unit_cost, 2),
                'received_at' => $lot->received_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $lots->currentPage(),
                'last_page' => $lots->lastPage(),
                'per_page' => $lots->perPage(),
                'total' => $lots->total(),
            ],
        ]);
    }
}
