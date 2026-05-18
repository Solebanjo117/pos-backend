<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryLot;
use App\Models\ProductBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchInventoryController extends Controller
{
    /**
     * Get paginated list of batches grouped by batch_number with products.
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('fifo')) {
            return $this->fifoLots($request);
        }

        // Start with ProductBatch and join warehouse_batches to get quantities
        $query = ProductBatch::with(['product.pricing'])
            ->select('product_batches.*')
            ->join('warehouse_batches', 'product_batches.id', '=', 'warehouse_batches.product_batch_id')
            ->where('warehouse_batches.quantity', '>', 0)
            ->groupBy('product_batches.id', 'product_batches.product_id', 'product_batches.batch_number',
                'product_batches.expiry_date', 'product_batches.manufacturing_date',
                'product_batches.cost', 'product_batches.created_at', 'product_batches.updated_at');

        // Filter by Warehouse
        if ($request->has('warehouse_id')) {
            $query->where('warehouse_batches.warehouse_id', $request->warehouse_id);
        }

        // Filter by Search (batch number or product)
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('product_batches.batch_number', 'ilike', "%{$search}%")
                    ->orWhereHas('product', function ($q2) use ($search) {
                        $q2->where('name', 'ilike', "%{$search}%")
                            ->orWhere('sku_base', 'ilike', "%{$search}%");
                    });
            });
        }

        // Filter by Expiry Date
        if ($request->has('expiry_status')) {
            $now = now();
            if ($request->expiry_status === 'expired') {
                $query->whereDate('product_batches.expiry_date', '<', $now);
            } elseif ($request->expiry_status === 'expiring_soon') {
                $query->whereDate('product_batches.expiry_date', '>=', $now)
                    ->whereDate('product_batches.expiry_date', '<=', $now->copy()->addDays(30));
            }
        }

        // Get all matching batches first
        $allBatches = $query->get();

        // Group by batch_number
        $groupedBatches = $allBatches->groupBy('batch_number')->map(function ($batchGroup, $batchNumber) use ($request) {
            // Get all products in this batch
            $products = $batchGroup->map(function ($batch) use ($request) {
                // Get warehouse quantities for this product batch
                $warehouseQuery = $batch->warehouseBatches()
                    ->with('warehouse')
                    ->where('quantity', '>', 0);

                if ($request->has('warehouse_id')) {
                    $warehouseQuery->where('warehouse_id', $request->warehouse_id);
                }

                $warehouseBatches = $warehouseQuery->get();

                // Get pricing from product's default pricing
                $pricing = $batch->product->pricing;

                return [
                    'product_id' => $batch->product_id,
                    'product_name' => $batch->product->name,
                    'product_sku' => $batch->product->sku_base,
                    'base_price' => $pricing ? (float) $pricing->base_price : null,
                    'total_quantity' => $warehouseBatches->sum('quantity'),
                    'warehouses' => $warehouseBatches->map(fn ($wb) => [
                        'warehouse_id' => $wb->warehouse_id,
                        'warehouse_name' => $wb->warehouse->name,
                        'quantity' => $wb->quantity,
                    ])->toArray(),
                ];
            })->values();

            $firstBatch = $batchGroup->first();
            $totalQuantity = $products->sum('total_quantity');

            return [
                'batch_number' => $batchNumber,
                'expiry_date' => $firstBatch->expiry_date,
                'manufacturing_date' => $firstBatch->manufacturing_date,
                'cost' => $firstBatch->cost,
                'notes' => $firstBatch->notes,
                'total_quantity' => $totalQuantity,
                'product_count' => $products->count(),
                'products' => $products->toArray(),
            ];
        })->values();

        // Manual pagination
        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $total = $groupedBatches->count();
        $paginatedBatches = $groupedBatches->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $paginatedBatches,
            'meta' => [
                'current_page' => (int) $page,
                'last_page' => (int) ceil($total / $perPage),
                'per_page' => (int) $perPage,
                'total' => $total,
            ],
        ]);
    }

    private function fifoLots(Request $request): JsonResponse
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

    /**
     * Update the notes for all product batches that share the same batch_number.
     * PUT /api/inventory/batches/{batch_number}/notes
     */
    public function updateNotes(Request $request, string $batchNumber): JsonResponse
    {
        $request->validate([
            'notes' => 'nullable|string',
        ]);

        $updated = ProductBatch::where('batch_number', $batchNumber)->update([
            'notes' => $request->notes,
        ]);

        if ($updated === 0) {
            return response()->json(['message' => 'Lote no encontrado'], 404);
        }

        return response()->json(['message' => 'Notas del lote actualizadas con éxito']);
    }
}
