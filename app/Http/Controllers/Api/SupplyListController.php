<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplyListItem;
use App\Models\PurchaseOrder;
use App\Models\ProductVariant;
use App\Models\ProductInventory;
use App\Models\InventoryMovement;
use App\Services\SmartReorderService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SupplyListController extends Controller
{
    /**
     * GET /api/v1/supply-list
     * List all pending supply list items for the current warehouse context.
     */
    public function index(Request $request): JsonResponse
    {
        $items = SupplyListItem::with([
            'product:id,name,sku_base',
            'variant:id,sku,brand_id',
            'variant.brand:id,name',
            'supplier:id,name',
            'addedBy:id,name',
        ])->latest()->get();

        return response()->json(['data' => $items]);
    }

    /**
     * POST /api/v1/supply-list
     * Add a product to the supply list.
     */
    public function store(Request $request): JsonResponse
    {
        $this->normalizeIntegerPercentField($request, 'tax_rate');

        $validated = $request->validate([
            'product_id'      => 'required|exists:products,id',
            'variant_id'      => 'nullable|exists:product_variants,id',
            'supplier_id'     => 'nullable|exists:suppliers,id',
            'quantity_needed' => 'nullable|numeric|min:0.01',
            'unit_cost'       => 'nullable|numeric|min:0',
            'tax_rate'        => 'nullable|integer|min:0|max:100',
            'sale_price'      => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string|max:500',
        ]);

        $warehouseId = (int) ($request->input('warehouse_id') ?? 0);
        if ($warehouseId <= 0) {
            return response()->json([
                'message' => 'Se requiere contexto de almacén para abastecer stock.',
            ], 422);
        }

        $stockToAdd = (int) ceil((float) ($validated['quantity_needed'] ?? 1));

        $item = DB::transaction(function () use ($validated, $warehouseId, $stockToAdd) {
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
                'product_id'      => $productId,
                'variant_id'      => $variantId,
                'supplier_id'     => $validated['supplier_id'] ?? null,
                'added_by'        => Auth::id(),
                'quantity_needed' => $validated['quantity_needed'] ?? 1,
                'stock_added_qty' => $stockToAdd,
                'unit_cost'       => $validated['unit_cost'] ?? 0,
                'tax_rate'        => $validated['tax_rate'] ?? 7,
                'sale_price'      => $validated['sale_price'] ?? 0,
                'notes'           => $validated['notes'] ?? null,
            ]);

            if ($variantId) {
                $inventory = ProductInventory::firstOrCreate(
                    [
                        'variant_id' => $variantId,
                        'warehouse_id' => $warehouseId,
                    ],
                    [
                        'product_id' => $productId,
                        'quantity_available' => 0,
                        'quantity_reserved' => 0,
                        'reorder_point' => 10,
                        'reorder_quantity' => 50,
                    ]
                );
            } else {
                $inventory = ProductInventory::firstOrCreate(
                    [
                        'product_id' => $productId,
                        'variant_id' => null,
                        'warehouse_id' => $warehouseId,
                    ],
                    [
                        'quantity_available' => 0,
                        'quantity_reserved' => 0,
                        'reorder_point' => 10,
                        'reorder_quantity' => 50,
                    ]
                );
            }

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
                'notes' => 'Stock agregado al crear item en lista de abastecimiento',
                'user_id' => Auth::id(),
                'supplier_id' => $validated['supplier_id'] ?? null,
            ]);

            return $item;
        });

        $item->load(['product:id,name,sku_base', 'variant:id,sku', 'supplier:id,name', 'addedBy:id,name']);

        return response()->json([
            'message' => 'Ítem agregado a la lista de abastecimiento y stock actualizado',
            'stock_added' => $stockToAdd,
            'warehouse_id' => $warehouseId,
            'data'    => $item,
        ], 201);
    }

    /**
     * PUT /api/v1/supply-list/{id}
     * Update a supply list item (inline editing).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $item = SupplyListItem::findOrFail($id);

        $this->normalizeIntegerPercentField($request, 'tax_rate');

        $validated = $request->validate([
            'supplier_id'     => 'nullable|exists:suppliers,id',
            'quantity_needed' => 'nullable|numeric|min:0.01',
            'unit_cost'       => 'nullable|numeric|min:0',
            'tax_rate'        => 'nullable|integer|min:0|max:100',
            'sale_price'      => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string|max:500',
        ]);

        // Handle null supplier explicitly
        if (array_key_exists('supplier_id', $request->all())) {
            $validated['supplier_id'] = $request->input('supplier_id');
        }

        $item->update($validated);
        $item->load(['product:id,name,sku_base', 'variant:id,sku', 'supplier:id,name', 'addedBy:id,name']);

        return response()->json([
            'message' => 'Ítem actualizado',
            'data'    => $item,
        ]);
    }

    /**
     * DELETE /api/v1/supply-list/{id}
     * Remove an item from the supply list.
     */
    public function destroy(int $id): JsonResponse
    {
        $item = SupplyListItem::findOrFail($id);
        $item->delete();

        return response()->json(['message' => 'Ítem eliminado de la lista']);
    }

    /**
     * POST /api/v1/supply-list/convert
     * Convert supply list items into Purchase Orders grouped by supplier.
     * Items without supplier create a PO with supplier_id = null (LL-011).
     * 
     * If item_ids array is provided, only convert those specific items.
     */
    public function convert(Request $request): JsonResponse
    {
        $warehouseId = $request->header('X-Warehouse-Id') ?? $request->input('warehouse_id');

        if (!$warehouseId) {
            return response()->json(['message' => 'Se requiere contexto de almacén'], 422);
        }

        // Check if filtering by specific item IDs
        $itemIds = $request->input('item_ids');
        
        $query = SupplyListItem::with(['product', 'variant']);
        
        if ($itemIds && is_array($itemIds) && !empty($itemIds)) {
            $query->whereIn('id', $itemIds);
        }
        
        $items = $query->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => 'No hay ítems para convertir'], 422);
        }

        $createdOrders = [];

        DB::transaction(function () use ($items, $warehouseId, &$createdOrders) {
            // Group by supplier_id (null = no supplier)
            $grouped = $items->groupBy(fn($item) => $item->supplier_id ?? 'sin_proveedor');

            foreach ($grouped as $supplierId => $groupItems) {
                $realSupplierId = ($supplierId === 'sin_proveedor') ? null : (int) $supplierId;

                $po = PurchaseOrder::create([
                    'supplier_id'  => $realSupplierId,
                    'warehouse_id' => $warehouseId,
                    'user_id'      => Auth::id(),
                    'status'       => 'draft',
                    'order_date'   => now()->toDateString(),
                    'notes'        => 'Generada desde Lista de Abastecimiento',
                    'subtotal'     => 0,
                    'tax_amount'   => 0,
                    'shipping_cost'=> 0,
                    'total'        => 0,
                ]);

                foreach ($groupItems as $si) {
                    $orderedQty = (int) ceil($si->quantity_needed);
                    $preAppliedQty = min($orderedQty, max(0, (int) ($si->stock_added_qty ?? 0)));

                    $po->items()->create([
                        'product_id'       => $si->product_id,
                        'variant_id'       => $si->variant_id,
                        'quantity_ordered' => $orderedQty,
                        'pre_stock_applied_qty' => $preAppliedQty,
                        'unit_cost'        => $si->unit_cost ?? 0,
                        'notes'            => $si->notes,
                    ]);
                }

                $po->calculateTotals();
                $po->load('supplier:id,name');

                $createdOrders[] = [
                    'id'          => $po->id,
                    'po_number'   => $po->po_number,
                    'supplier'    => $po->supplier?->name ?? 'Sin proveedor',
                    'items_count' => $groupItems->count(),
                ];
            }

            // Delete all converted items
            $items->each->delete();
        });

        return response()->json([
            'message'        => count($createdOrders) . ' orden(es) de compra creada(s)',
            'orders_created' => $createdOrders,
        ]);
    }

    /**
     * POST /api/v1/supply-list/import-low-stock
     * Import products with low stock into the supply list.
     * Uses SmartReorderService to detect products below reorder point.
     */
    public function importLowStock(Request $request, SmartReorderService $smartReorderService): JsonResponse
    {
        $warehouseId = $request->input('warehouse_id') ?? $request->header('X-Warehouse-Id');
        
        // Get low stock products grouped by supplier
        $lowStockData = $smartReorderService->getLowStockProducts($warehouseId ? (int) $warehouseId : null);
        
        $imported = 0;
        $skipped = 0;
        $importedItems = [];

        DB::transaction(function () use ($lowStockData, &$imported, &$skipped, &$importedItems) {
            foreach ($lowStockData as $supplierGroup) {
                $supplierId = $supplierGroup['supplier']['id'] ?? null;
                
                foreach ($supplierGroup['items'] as $item) {
                    // Check if product+variant already exists in supply list
                    $exists = SupplyListItem::where('product_id', $item['product_id'])
                        ->where(function ($q) use ($item) {
                            if (isset($item['variant_id']) && $item['variant_id']) {
                                $q->where('variant_id', $item['variant_id']);
                            } else {
                                $q->whereNull('variant_id');
                            }
                        })
                        ->exists();
                    
                    if ($exists) {
                        $skipped++;
                        continue;
                    }
                    
                    $newItem = SupplyListItem::create([
                        'product_id'      => $item['product_id'],
                        'variant_id'      => $item['variant_id'] ?? null,
                        'supplier_id'     => $supplierId,
                        'added_by'        => Auth::id(),
                        'quantity_needed' => $item['suggested_quantity'] ?? 1,
                        'unit_cost'       => $item['unit_cost'] ?? 0,
                        'tax_rate'        => 7, // Panama ITBM default
                        'sale_price'      => 0,
                        'notes'           => 'Importado desde Stock Bajo - ' . ($item['warehouse_name'] ?? 'Almacén'),
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
                ? "{$imported} producto(s) importado(s) a la lista" 
                : 'No hay productos nuevos para importar',
            'imported_count' => $imported,
            'skipped_count' => $skipped,
            'items' => $importedItems,
        ]);
    }

    private function normalizeIntegerPercentField(Request $request, string $field): void
    {
        if (!$request->has($field)) {
            return;
        }

        $value = $request->input($field);
        if (is_numeric($value)) {
            $request->merge([$field => (int) round((float) $value)]);
        }
    }
}
