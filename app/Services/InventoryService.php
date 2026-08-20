<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Support\Collection;

class InventoryService
{
    /**
     * Initialize stock for a product across warehouses.
     */
    public function initializeStock(Product $product, array $inventoryData): void
    {
        foreach ($inventoryData as $data) {
            $product->inventory()->create([
                'warehouse_id' => $data['warehouse_id'],
                'stock_available' => $data['stock_available'] ?? 0,
                'stock_reserved' => $data['stock_reserved'] ?? 0,
                'stock_on_order' => $data['stock_on_order'] ?? 0,
                'reorder_point' => $data['reorder_point'] ?? 0,
                'target_level' => $data['target_level'] ?? 0,
            ]);
        }
    }

    /**
     * Update inventory for a specific warehouse.
     */
    public function updateInventory(int $productId, int $warehouseId, array $data): Inventory
    {
        return Inventory::updateOrCreate(
            [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
            ],
            $data
        );
    }

    /**
     * Reserve stock for an order.
     */
    public function reserveStock(Product $product, int $warehouseId, int $quantity): bool
    {
        $inventory = $product->inventory()
            ->where('warehouse_id', $warehouseId)
            ->first();

        if (! $inventory || $inventory->stock_available < $quantity) {
            return false;
        }

        $inventory->decrement('stock_available', $quantity);
        $inventory->increment('stock_reserved', $quantity);

        return true;
    }

    /**
     * Release reserved stock.
     */
    public function releaseStock(Product $product, int $warehouseId, int $quantity): void
    {
        $inventory = $product->inventory()
            ->where('warehouse_id', $warehouseId)
            ->first();

        if ($inventory) {
            $inventory->decrement('stock_reserved', $quantity);
            $inventory->increment('stock_available', $quantity);
        }
    }

    /**
     * Get products that need reordering.
     */
    public function getProductsNeedingReorder(): Collection
    {
        return Inventory::whereColumn('stock_available', '<=', 'reorder_point')
            ->with('product', 'warehouse')
            ->get();
    }

    /**
     * Get total stock for a product across all warehouses.
     */
    public function getTotalStock(Product $product): array
    {
        $inventory = $product->inventory;

        return [
            'total_available' => $inventory->sum('stock_available'),
            'total_reserved' => $inventory->sum('stock_reserved'),
            'total_on_order' => $inventory->sum('stock_on_order'),
        ];
    }
}
