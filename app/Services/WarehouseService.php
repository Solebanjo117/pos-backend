<?php

namespace App\Services;

use App\Models\Warehouse;
use App\Models\ProductInventory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WarehouseService
{
    /**
     * Get paginated list of warehouses with filters
     */
    public function getWarehouses(array $filters = [], int $perPage = 20)
    {
        $query = Warehouse::query();

        // Search filter
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('code', 'ILIKE', "%{$search}%")
                  ->orWhere('location', 'ILIKE', "%{$search}%");
            });
        }

        // Status filter
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Order by
        $orderBy = $filters['order_by'] ?? 'name';
        $orderDirection = $filters['order_direction'] ?? 'asc';
        $query->orderBy($orderBy, $orderDirection);

        return $query->paginate($perPage);
    }

    /**
     * Get warehouse by ID with stats
     */
    public function getWarehouseById(int $id)
    {
        $warehouse = Warehouse::findOrFail($id);
        
        // Add statistics
        $warehouse->total_products = $this->getTotalProducts($id);
        $warehouse->total_units = $this->getTotalUnits($id);
        $warehouse->total_value = $this->getTotalValue($id);
        $warehouse->low_stock_products = $this->getLowStockCount($id);
        $warehouse->out_of_stock_products = $this->getOutOfStockCount($id);

        return $warehouse;
    }

    /**
     * Create a new warehouse
     */
    public function createWarehouse(array $data): Warehouse
    {
        return Warehouse::create($data);
    }

    /**
     * Update warehouse
     */
    public function updateWarehouse(int $id, array $data): Warehouse
    {
        $warehouse = Warehouse::findOrFail($id);
        $warehouse->update($data);
        return $warehouse->fresh();
    }

    /**
     * Delete warehouse
     */
    public function deleteWarehouse(int $id): bool
    {
        $warehouse = Warehouse::findOrFail($id);
        
        // Check if warehouse has inventory
        $hasInventory = ProductInventory::where('warehouse_id', $id)
            ->where('quantity_available', '>', 0)
            ->exists();

        if ($hasInventory) {
            throw new \Exception('No se puede eliminar un almacén con inventario activo.');
        }

        return $warehouse->delete();
    }

    /**
     * Get inventory for a specific warehouse
     */
    public function getWarehouseInventory(int $warehouseId, array $filters = [], int $perPage = 20)
    {
        $query = ProductInventory::with(['product', 'variant.brand'])
            ->where('warehouse_id', $warehouseId);

        // Filter by low stock
        if (!empty($filters['low_stock'])) {
            $query->whereColumn('quantity_available', '<=', 'reorder_point');
        }

        // Filter by out of stock
        if (!empty($filters['out_of_stock'])) {
            $query->where('quantity_available', 0);
        }

        // Search by product name or SKU
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('sku_base', 'ILIKE', "%{$search}%");
            });
        }

        // Order by
        $orderBy = $filters['order_by'] ?? 'updated_at';
        $orderDirection = $filters['order_direction'] ?? 'desc';
        $query->orderBy($orderBy, $orderDirection);

        return $query->paginate($perPage);
    }

    /**
     * Get total number of different products in warehouse
     */
    private function getTotalProducts(int $warehouseId): int
    {
        return ProductInventory::where('warehouse_id', $warehouseId)
            ->where('quantity_available', '>', 0)
            ->distinct('product_id')
            ->count('product_id');
    }

    /**
     * Get total units in warehouse
     */
    private function getTotalUnits(int $warehouseId): int
    {
        return ProductInventory::where('warehouse_id', $warehouseId)
            ->sum('quantity_available') ?? 0;
    }

    /**
     * Get total value of inventory in warehouse
     */
    private function getTotalValue(int $warehouseId): float
    {
        return ProductInventory::where('warehouse_id', $warehouseId)
            ->join('products', 'product_inventory.product_id', '=', 'products.id')
            ->join('product_pricing', 'products.id', '=', 'product_pricing.product_id')
            ->selectRaw('SUM(product_inventory.quantity_available * product_pricing.base_price) as total')
            ->value('total') ?? 0.0;
    }

    /**
     * Get count of products with low stock
     */
    private function getLowStockCount(int $warehouseId): int
    {
        return ProductInventory::where('warehouse_id', $warehouseId)
            ->whereColumn('quantity_available', '<=', 'reorder_point')
            ->where('quantity_available', '>', 0)
            ->count();
    }

    /**
     * Get count of products out of stock
     */
    private function getOutOfStockCount(int $warehouseId): int
    {
        return ProductInventory::where('warehouse_id', $warehouseId)
            ->where('quantity_available', 0)
            ->count();
    }

    /**
     * Get all warehouses for dropdown/select
     */
    public function getAllWarehouses(): Collection
    {
        return Warehouse::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }
}
