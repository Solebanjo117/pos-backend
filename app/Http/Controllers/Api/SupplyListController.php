<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SupplyListItem;
use App\Services\SmartReorderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SupplyListController extends Controller
{
    /**
     * GET /api/v1/supply-list
     * List daily supply list items for the current warehouse context.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'supply_date' => 'nullable|date',
        ]);

        $warehouseId = $this->resolveWarehouseId($request, $validated['warehouse_id'] ?? null);
        $supplyDate = $this->resolveSupplyDate($request);

        $items = $this->supplyListQuery($warehouseId, $supplyDate)
            ->latest()
            ->get();

        return response()->json([
            'data' => $items,
            'warehouse_id' => $warehouseId,
            'supply_date' => $supplyDate,
        ]);
    }

    /**
     * POST /api/v1/supply-list
     * Add a product to the daily supply list and pre-apply stock.
     */
    public function store(Request $request): JsonResponse
    {
        $this->normalizeIntegerPercentField($request, 'tax_rate');

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'supply_date' => 'nullable|date',
            'quantity_needed' => 'nullable|numeric|min:0.01',
            'unit_cost' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|integer|min:0|max:100',
            'sale_price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $warehouseId = $this->resolveWarehouseId($request, $validated['warehouse_id'] ?? null);
        if ($warehouseId <= 0) {
            return response()->json([
                'message' => 'Se requiere contexto de almacen para abastecer stock.',
            ], 422);
        }

        $supplyDate = $this->resolveSupplyDate($request);
        $stockToAdd = (int) ceil((float) ($validated['quantity_needed'] ?? 1));

        $item = DB::transaction(function () use ($validated, $warehouseId, $supplyDate, $stockToAdd) {
            $variantId = $validated['variant_id'] ?? null;
            $productId = (int) $validated['product_id'];

            if ($variantId) {
                $variant = ProductVariant::query()
                    ->select(['id', 'product_id'])
                    ->find($variantId);

                if ($variant) {
                    $productId = (int) $variant->product_id;
                }
            }

            $item = SupplyListItem::create([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'warehouse_id' => $warehouseId,
                'supply_date' => $supplyDate,
                'added_by' => Auth::id(),
                'quantity_needed' => $validated['quantity_needed'] ?? 1,
                'stock_added_qty' => $stockToAdd,
                'unit_cost' => $validated['unit_cost'] ?? 0,
                'tax_rate' => $validated['tax_rate'] ?? 7,
                'sale_price' => $validated['sale_price'] ?? 0,
                'notes' => $validated['notes'] ?? null,
            ]);

            $inventoryLookup = [
                'warehouse_id' => $warehouseId,
                'variant_id' => $variantId,
            ];

            if ($variantId) {
                $inventoryLookup['variant_id'] = $variantId;
            } else {
                $inventoryLookup['product_id'] = $productId;
            }

            $inventory = ProductInventory::firstOrCreate(
                $inventoryLookup,
                [
                    'product_id' => $productId,
                    'quantity_available' => 0,
                    'quantity_reserved' => 0,
                    'reorder_point' => 10,
                    'reorder_quantity' => 50,
                ]
            );

            $inventory->quantity_available += $stockToAdd;
            $inventory->last_restocked_at = now();
            $inventory->save();

            InventoryMovement::create([
                'movement_type' => 'in',
                'reason' => 'Ingreso desde lista de abastecimiento',
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'quantity' => $stockToAdd,
                'reference_type' => 'supply_list',
                'reference_id' => $item->id,
                'notes' => 'Stock preaplicado desde OC diaria',
                'user_id' => Auth::id(),
                'supplier_id' => $validated['supplier_id'] ?? null,
            ]);

            return $item;
        });

        $item->load($this->itemRelations());

        return response()->json([
            'message' => 'Item agregado a la OC diaria y stock preaplicado',
            'stock_added' => $stockToAdd,
            'warehouse_id' => $warehouseId,
            'supply_date' => $supplyDate,
            'data' => $item,
        ], 201);
    }

    /**
     * PUT /api/v1/supply-list/{id}
     * Update a supply list item inline.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->normalizeIntegerPercentField($request, 'tax_rate');

        $validated = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'supply_date' => 'nullable|date',
            'quantity_needed' => 'nullable|numeric|min:0.01',
            'unit_cost' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|integer|min:0|max:100',
            'sale_price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $warehouseId = $this->resolveWarehouseId($request, $validated['warehouse_id'] ?? null);
        $supplyDate = $this->resolveSupplyDate($request);
        $item = $this->findScopedItem($id, $warehouseId, $supplyDate);

        if (array_key_exists('supplier_id', $request->all())) {
            $validated['supplier_id'] = $request->input('supplier_id');
        }

        unset($validated['warehouse_id'], $validated['supply_date']);

        $item->update($validated);
        $item->load($this->itemRelations());

        return response()->json([
            'message' => 'Item actualizado',
            'data' => $item,
        ]);
    }

    /**
     * DELETE /api/v1/supply-list/{id}
     * Remove an item from the daily supply list.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'supply_date' => 'nullable|date',
        ]);

        $warehouseId = $this->resolveWarehouseId($request, $validated['warehouse_id'] ?? null);
        $supplyDate = $this->resolveSupplyDate($request);
        $item = $this->findScopedItem($id, $warehouseId, $supplyDate);

        $item->delete();

        return response()->json(['message' => 'Item eliminado de la OC diaria']);
    }

    /**
     * POST /api/v1/supply-list/convert
     * Convert daily supply list items into purchase orders grouped by supplier.
     */
    public function convert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'supply_date' => 'nullable|date',
            'item_ids' => 'nullable|array',
            'item_ids.*' => 'integer|exists:supply_list_items,id',
        ]);

        $warehouseId = $this->resolveWarehouseId($request, $validated['warehouse_id'] ?? null);
        $supplyDate = $this->resolveSupplyDate($request);

        if ($warehouseId <= 0) {
            return response()->json(['message' => 'Se requiere contexto de almacen'], 422);
        }

        $query = $this->supplyListQuery($warehouseId, $supplyDate);

        if (! empty($validated['item_ids'])) {
            $query->whereIn('id', $validated['item_ids']);
        }

        $items = $query->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => 'No hay items para convertir'], 422);
        }

        $createdOrders = [];

        DB::transaction(function () use ($items, $warehouseId, $supplyDate, &$createdOrders) {
            $grouped = $items->groupBy(fn (SupplyListItem $item) => $item->supplier_id ?? 'sin_proveedor');

            foreach ($grouped as $supplierId => $groupItems) {
                $realSupplierId = ($supplierId === 'sin_proveedor') ? null : (int) $supplierId;
                $po = PurchaseOrder::create([
                    'supplier_id' => $realSupplierId,
                    'warehouse_id' => $warehouseId,
                    'user_id' => Auth::id(),
                    'status' => 'draft',
                    'order_date' => $supplyDate,
                    'expected_date' => $supplyDate,
                    'notes' => 'Generada desde OC diaria / Lista de Abastecimiento',
                    'subtotal' => 0,
                    'tax_amount' => 0,
                    'shipping_cost' => 0,
                    'total' => 0,
                ]);

                foreach ($groupItems as $supplyItem) {
                    $orderedQty = (int) ceil((float) $supplyItem->quantity_needed);
                    $preAppliedQty = min($orderedQty, max(0, (int) ($supplyItem->stock_added_qty ?? 0)));

                    $po->items()->create([
                        'supplier_id' => $supplyItem->supplier_id,
                        'product_id' => $supplyItem->product_id,
                        'variant_id' => $supplyItem->variant_id,
                        'quantity_ordered' => $orderedQty,
                        'pre_stock_applied_qty' => $preAppliedQty,
                        'unit_cost' => $supplyItem->unit_cost ?? 0,
                        'tax_rate' => $supplyItem->tax_rate ?? 7,
                        'notes' => $supplyItem->notes,
                    ]);
                }

                $po->calculateTotals();
                $po->load('supplier:id,name');

                $createdOrders[] = [
                    'id' => $po->id,
                    'po_number' => $po->po_number,
                    'supplier' => $po->supplier?->name ?? 'Sin proveedor',
                    'items_count' => $groupItems->count(),
                ];
            }

            $items->each->delete();
        });

        return response()->json([
            'message' => count($createdOrders).' orden(es) de compra creada(s)',
            'orders_created' => $createdOrders,
        ]);
    }

    /**
     * POST /api/v1/supply-list/import-low-stock
     * Import low stock products into today's supply list.
     */
    public function importLowStock(Request $request, SmartReorderService $smartReorderService): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'supply_date' => 'nullable|date',
        ]);

        $warehouseId = $this->resolveWarehouseId($request, $validated['warehouse_id'] ?? null);
        $supplyDate = $this->resolveSupplyDate($request);

        if ($warehouseId <= 0) {
            return response()->json(['message' => 'Se requiere contexto de almacen'], 422);
        }

        $lowStockData = $smartReorderService->getLowStockProducts($warehouseId);

        $imported = 0;
        $skipped = 0;
        $importedItems = [];

        DB::transaction(function () use ($lowStockData, $warehouseId, $supplyDate, &$imported, &$skipped, &$importedItems) {
            foreach ($lowStockData as $supplierGroup) {
                $supplierId = $supplierGroup['supplier']['id'] ?? null;

                foreach ($supplierGroup['items'] as $item) {
                    $exists = SupplyListItem::where('product_id', $item['product_id'])
                        ->where('warehouse_id', $warehouseId)
                        ->whereDate('supply_date', $supplyDate)
                        ->where(function ($query) use ($item) {
                            if (! empty($item['variant_id'])) {
                                $query->where('variant_id', $item['variant_id']);
                            } else {
                                $query->whereNull('variant_id');
                            }
                        })
                        ->exists();

                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    $newItem = SupplyListItem::create([
                        'product_id' => $item['product_id'],
                        'variant_id' => $item['variant_id'] ?? null,
                        'supplier_id' => $supplierId,
                        'warehouse_id' => $warehouseId,
                        'supply_date' => $supplyDate,
                        'added_by' => Auth::id(),
                        'quantity_needed' => $item['suggested_quantity'] ?? 1,
                        'stock_added_qty' => 0,
                        'unit_cost' => $item['unit_cost'] ?? 0,
                        'tax_rate' => 7,
                        'sale_price' => 0,
                        'notes' => 'Importado desde Stock Bajo - '.($item['warehouse_name'] ?? 'Almacen'),
                    ]);

                    $importedItems[] = [
                        'id' => $newItem->id,
                        'product_name' => $item['product']['name'] ?? 'Producto',
                        'supplier_name' => $supplierGroup['supplier']['name'] ?? 'Sin proveedor',
                        'quantity' => $item['suggested_quantity'] ?? 1,
                    ];

                    $imported++;
                }
            }
        });

        return response()->json([
            'message' => $imported > 0
                ? "{$imported} producto(s) importado(s) a la OC diaria"
                : 'No hay productos nuevos para importar',
            'imported_count' => $imported,
            'skipped_count' => $skipped,
            'items' => $importedItems,
        ]);
    }

    private function normalizeIntegerPercentField(Request $request, string $field): void
    {
        if (! $request->has($field)) {
            return;
        }

        $value = $request->input($field);
        if (is_numeric($value)) {
            $request->merge([$field => (int) round((float) $value)]);
        }
    }

    private function resolveWarehouseId(Request $request, mixed $warehouseId = null): int
    {
        return (int) (
            $warehouseId
            ?? $request->input('warehouse_id')
            ?? $request->header('X-Warehouse-Id')
            ?? $request->user()?->current_warehouse_id
            ?? 0
        );
    }

    private function resolveSupplyDate(Request $request): string
    {
        return (string) ($request->input('supply_date') ?? now()->toDateString());
    }

    /**
     * @return array<int, string>
     */
    private function itemRelations(): array
    {
        return [
            'product:id,name,sku_base',
            'variant:id,sku,brand_id',
            'variant.brand:id,name',
            'supplier:id,name',
            'warehouse:id,name',
            'addedBy:id,name',
        ];
    }

    private function supplyListQuery(int $warehouseId, string $supplyDate): Builder
    {
        return SupplyListItem::with($this->itemRelations())
            ->where('warehouse_id', $warehouseId)
            ->whereDate('supply_date', $supplyDate);
    }

    private function findScopedItem(int $id, int $warehouseId, string $supplyDate): SupplyListItem
    {
        return $this->supplyListQuery($warehouseId, $supplyDate)
            ->whereKey($id)
            ->firstOrFail();
    }
}
