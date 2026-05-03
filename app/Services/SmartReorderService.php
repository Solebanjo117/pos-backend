<?php

namespace App\Services;

use App\Models\ProductInventory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

class SmartReorderService
{
    /**
     * Get products that need reordering grouped by preferred supplier
     */
    public function getLowStockProducts(?int $warehouseId = null)
    {
        $query = ProductInventory::with([
            'variant.suppliers' => function ($q) {
                $q->orderByDesc('is_preferred');
            },
            'product.productSuppliers' => function ($q) {
                $q->orderByDesc('is_preferred');
            },
            'product.productSuppliers.supplier',
            'product.pricing',
            'warehouse',
        ])
            ->whereColumn('quantity_available', '<=', 'reorder_point')
            ->where('quantity_available', '>=', 0); // Only positive stock for reordering

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $lowStockItems = $query->get();

        // Group by supplier
        $groupedBySupplier = [];

        foreach ($lowStockItems as $inventoryRecord) {
            $product = $inventoryRecord->product;
            $variant = $inventoryRecord->variant;

            $variantSupplier = $variant?->suppliers?->first(fn ($supplier) => (bool) ($supplier->pivot->is_preferred ?? false))
                ?? $variant?->suppliers?->first();

            $preferredSupplier = $variantSupplier
                ? (object) [
                    'supplier_id' => $variantSupplier->id,
                    'supplier' => $variantSupplier,
                    'supplier_cost' => $variantSupplier->pivot->supplier_cost ?? 0,
                    'lead_time_days' => $variantSupplier->pivot->lead_time_days ?? 0,
                    'minimum_order_quantity' => $variantSupplier->pivot->minimum_order_quantity ?? 1,
                ]
                : ($product->productSuppliers
                    ->where('is_preferred', true)
                    ->first() ?? $product->productSuppliers->first());

            if (! $preferredSupplier) {
                continue; // Skip products without suppliers
            }

            $supplierId = $preferredSupplier->supplier_id;

            if (! isset($groupedBySupplier[$supplierId])) {
                $groupedBySupplier[$supplierId] = [
                    'supplier' => $preferredSupplier->supplier,
                    'items' => [],
                ];
            }

            $suggestedQuantity = $inventoryRecord->reorder_quantity > 0
                ? $inventoryRecord->reorder_quantity
                : max(1, $inventoryRecord->reorder_point - $inventoryRecord->quantity_available);

            // Get unit cost: prefer supplier cost, fallback to product pricing cost
            $unitCost = $preferredSupplier->supplier_cost
                ?? $product->pricing?->cost
                ?? 0;

            $groupedBySupplier[$supplierId]['items'][] = [
                'inventory_id' => $inventoryRecord->id,
                'product_id' => $product->id,
                'variant_id' => $inventoryRecord->variant_id,
                'product' => [
                    'id' => $product->id,
                    'sku' => $product->sku_base,
                    'name' => $product->name,
                ],
                'warehouse_id' => $inventoryRecord->warehouse_id,
                'warehouse_name' => $inventoryRecord->warehouse->name,
                'current_stock' => $inventoryRecord->quantity_available,
                'reorder_point' => $inventoryRecord->reorder_point,
                'reorder_quantity' => $inventoryRecord->reorder_quantity,
                'suggested_quantity' => $suggestedQuantity,
                'unit_cost' => (float) $unitCost,
                'lead_time_days' => $preferredSupplier->lead_time_days,
                'minimum_order_qty' => $preferredSupplier->minimum_order_quantity,
            ];
        }

        return array_values($groupedBySupplier);
    }

    /**
     * Create multiple purchase orders from smart reorder suggestions
     */
    public function batchCreatePurchaseOrders(array $orders, int $userId): array
    {
        $createdOrders = [];

        DB::beginTransaction();

        try {
            foreach ($orders as $orderData) {
                $po = PurchaseOrder::create([
                    'supplier_id' => $orderData['supplier_id'],
                    'warehouse_id' => $orderData['warehouse_id'],
                    'user_id' => $userId,
                    'order_date' => now(),
                    'expected_date' => $orderData['expected_date'] ?? null,
                    'status' => 'draft',
                    'notes' => $orderData['notes'] ?? 'Generado automáticamente por Smart Reorder',
                ]);

                foreach ($orderData['items'] as $item) {
                    PurchaseOrderItem::create([
                        'purchase_order_id' => $po->id,
                        'product_id' => $item['product_id'],
                        'quantity_ordered' => $item['quantity'],
                        'unit_cost' => $item['unit_cost'],
                        'notes' => $item['notes'] ?? null,
                    ]);
                }

                // Recalculate totals
                $po->load('items');
                $po->calculateTotals();

                $createdOrders[] = $po->load(['supplier', 'warehouse', 'items.product']);
            }

            DB::commit();

            return $createdOrders;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get statistics for low stock items
     */
    public function getLowStockStats(?int $warehouseId = null): array
    {
        $query = ProductInventory::with(['product.productSuppliers'])
            ->whereColumn('quantity_available', '<=', 'reorder_point')
            ->where('quantity_available', '>=', 0);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $items = $query->get();
        $total = $items->count();
        $critical = $items->where('quantity_available', 0)->count();
        $low = $items->where('quantity_available', '>', 0)->count();

        // Count products without suppliers (skipped in smart reorder)
        $withoutSupplier = $items->filter(function ($item) {
            return $item->product->productSuppliers->isEmpty();
        })->count();

        return [
            'total_items' => $total,
            'critical_stock' => $critical,
            'low_stock' => $low,
            'without_supplier' => $withoutSupplier,
        ];
    }
}
