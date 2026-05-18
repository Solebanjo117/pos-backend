<?php

namespace App\Services;

use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

class InventoryFifoHistoryService
{
    public function __construct(
        private readonly InventoryFifoService $fifoService,
    ) {}

    /**
     * @return array{
     *     summary: array<string, mixed>,
     *     lots: EloquentCollection<int, InventoryLot>,
     *     purchase_movements: EloquentCollection<int, InventoryMovement>,
     *     sale_movements: EloquentCollection<int, InventoryMovement>
     * }
     */
    public function forVariant(Product $product, ProductVariant $variant, array $filters): array
    {
        $warehouseId = (int) $filters['warehouse_id'];
        $perPage = (int) ($filters['per_page'] ?? 25);

        $lots = InventoryLot::query()
            ->with(['warehouse', 'sourceMovement'])
            ->where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->where('warehouse_id', $warehouseId)
            ->where('quantity_available', '>', 0)
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('received_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('received_at', '<=', $date))
            ->orderBy('received_at')
            ->orderBy('id')
            ->limit($perPage)
            ->get();

        $purchaseMovements = $this->movementsQuery($product, $variant, $warehouseId, $filters)
            ->where('movement_type', 'in')
            ->latest('created_at')
            ->limit($perPage)
            ->get();

        $saleMovements = $this->movementsQuery($product, $variant, $warehouseId, $filters)
            ->where('movement_type', 'out')
            ->latest('created_at')
            ->limit($perPage)
            ->get();

        return [
            'summary' => $this->summary($product, $variant, $warehouseId),
            'lots' => $lots,
            'purchase_movements' => $purchaseMovements,
            'sale_movements' => $saleMovements,
        ];
    }

    private function movementsQuery(Product $product, ProductVariant $variant, int $warehouseId, array $filters): Builder
    {
        return InventoryMovement::query()
            ->with(['product', 'variant', 'warehouse', 'destinationWarehouse', 'user', 'productBatch', 'supplier', 'lotAllocations.lot', 'sourceLots'])
            ->where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->where(function ($query) use ($warehouseId): void {
                $query->where('warehouse_id', $warehouseId)
                    ->orWhere('destination_warehouse_id', $warehouseId);
            })
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date));
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Product $product, ProductVariant $variant, int $warehouseId): array
    {
        $inventory = ProductInventory::query()
            ->where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->where('warehouse_id', $warehouseId)
            ->first();

        $availableLots = InventoryLot::query()
            ->where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->where('warehouse_id', $warehouseId)
            ->where('quantity_available', '>', 0)
            ->get();

        $totalLotQuantity = (int) $availableLots->sum('quantity_available');
        $totalLotValue = round((float) $availableLots->sum(
            fn (InventoryLot $lot) => (int) $lot->quantity_available * (float) $lot->landed_unit_cost
        ), 2);

        return [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouseId,
            'stock_available' => (int) ($inventory?->quantity_available ?? 0),
            'fifo_cost_current' => round($this->fifoService->currentFifoCost($product->id, $variant->id, $warehouseId), 2),
            'total_lot_quantity' => $totalLotQuantity,
            'total_lot_value' => $totalLotValue,
            'average_landed_cost' => $totalLotQuantity > 0 ? round($totalLotValue / $totalLotQuantity, 2) : 0.0,
            'open_lots_count' => $availableLots->count(),
            'last_received_at' => $this->lastMovementDate($product, $variant, $warehouseId, 'in'),
            'last_sold_at' => $this->lastMovementDate($product, $variant, $warehouseId, 'out'),
        ];
    }

    private function lastMovementDate(Product $product, ProductVariant $variant, int $warehouseId, string $type): ?string
    {
        $movement = InventoryMovement::query()
            ->where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->where('warehouse_id', $warehouseId)
            ->where('movement_type', $type)
            ->latest('created_at')
            ->first();

        return $movement?->created_at instanceof Carbon
            ? $movement->created_at->toIso8601String()
            : null;
    }
}
