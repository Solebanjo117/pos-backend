<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryRequest;
use App\Http\Requests\UpdateInventoryLotRequest;
use App\Models\Inventory;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\ProductInventory;
use App\Models\Warehouse;
use App\Services\InventoryFifoService;
use App\Services\InventoryLotService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService,
        private InventoryFifoService $fifoService,
        private InventoryLotService $inventoryLotService,
    ) {}

    /**
     * Get inventory list with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProductInventory::with([
            'product.defaultVariant.brand',
            'product.defaultVariant.pricing',
            'variant.brand',
            'variant.pricing',
            'warehouse',
        ])->select('product_inventory.*');

        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->has('variant_id')) {
            $query->where('variant_id', $request->integer('variant_id'));
        }

        if ($request->boolean('low_stock')) {
            $query->whereColumn('quantity_available', '<=', 'reorder_point');
        }

        if ($request->filled('status')) {
            match ($request->string('status')->toString()) {
                'out_of_stock' => $query->where('quantity_available', '<=', 0),
                'low_stock' => $query->where('quantity_available', '>', 0)
                    ->whereColumn('quantity_available', '<=', 'reorder_point'),
                'optimal' => $query->where('reorder_quantity', '>', 0)
                    ->whereColumn('quantity_available', '>=', 'reorder_quantity'),
                'normal' => $query->where('quantity_available', '>', 0)
                    ->where(function ($query): void {
                        $query->whereColumn('quantity_available', '>', 'reorder_point')
                            ->where(function ($query): void {
                                $query->where('reorder_quantity', '<=', 0)
                                    ->orWhereColumn('quantity_available', '<', 'reorder_quantity');
                            });
                    }),
                default => null,
            };
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($query) use ($search): void {
                $query->whereHas('product', function ($q) use ($search): void {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku_base', 'like', "%{$search}%");
                })->orWhereHas('variant', function ($q) use ($search): void {
                    $q->where('sku', 'like', "%{$search}%")
                        ->orWhereHas('brand', fn ($brandQuery) => $brandQuery->where('name', 'like', "%{$search}%"));
                });
            });
        }

        $sortBy = $request->get('sort_by', 'product_id');
        $sortOrder = $request->get('sort_order', 'asc');

        if ($sortBy === 'stock_available') {
            $query->orderBy('quantity_available', $sortOrder);
        } elseif ($sortBy === 'reorder_point') {
            $query->orderBy('reorder_point', $sortOrder);
        } else {
            $query->orderBy('product_id', $sortOrder);
        }

        $perPage = min($request->get('per_page', 20), 100);
        $inventory = $query->paginate($perPage);

        return response()->json([
            'data' => $inventory->map(function (ProductInventory $item) {
                $variant = $item->variant ?? $item->product->defaultVariant;
                $pricing = $variant?->pricing ?? $item->product->defaultVariant?->pricing;
                $activePricing = $variant
                    ? $this->fifoService->currentFifoPricing($item->product_id, $variant->id, $item->warehouse_id)
                    : ['active_lot_id' => null, 'current_cost' => 0.0, 'current_sale_price' => (float) ($pricing?->base_price ?? 0)];
                $currentCost = round((float) $activePricing['current_cost'], 2);
                $currentSalePrice = round((float) $activePricing['current_sale_price'], 2);
                $inventoryValue = $this->inventoryValue($item->product_id, $item->variant_id, $item->warehouse_id);

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'product_name' => $item->product->name,
                    'product_sku' => $item->product->sku_base,
                    'variant_sku' => $variant?->sku,
                    'brand' => $variant?->brand?->name ?? 'N/A',
                    'warehouse_id' => $item->warehouse_id,
                    'warehouse_name' => $item->warehouse->name,
                    'stock_available' => $item->quantity_available,
                    'stock_reserved' => $item->quantity_reserved,
                    'stock_on_order' => 0,
                    'quantity_available' => $item->quantity_available,
                    'quantity_reserved' => $item->quantity_reserved,
                    'reorder_point' => $item->reorder_point,
                    'reorder_quantity' => $item->reorder_quantity,
                    'target_level' => $item->reorder_quantity,
                    'needs_reorder' => $item->isLowStock(),
                    'status' => $this->getStockStatus($item),
                    'active_lot_id' => $activePricing['active_lot_id'],
                    'current_cost' => $currentCost,
                    'current_sale_price' => $currentSalePrice,
                    'inventory_value' => $inventoryValue,
                    'fifo_cost_current' => $currentCost,
                    'fifo_sale_price_current' => $currentSalePrice,
                    'fifo_stock_value' => $inventoryValue,
                    'open_lots_count' => $this->openLotsCount($item->product_id, $item->variant_id, $item->warehouse_id),
                    'last_received_at' => $this->lastMovementDate($item->product_id, $item->variant_id, $item->warehouse_id, 'in'),
                    'last_sold_at' => $this->lastMovementDate($item->product_id, $item->variant_id, $item->warehouse_id, 'out'),
                    'base_price' => $pricing ? (float) $pricing->base_price : null,
                    'cost' => $pricing ? (float) $pricing->cost : null,
                ];
            }),
            'meta' => [
                'current_page' => $inventory->currentPage(),
                'from' => $inventory->firstItem(),
                'last_page' => $inventory->lastPage(),
                'per_page' => $inventory->perPage(),
                'to' => $inventory->lastItem(),
                'total' => $inventory->total(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $inventoryQuery = ProductInventory::query();
        $lotsQuery = InventoryLot::query()->where('quantity_available', '>', 0);
        $movementsQuery = InventoryMovement::query()->whereDate('created_at', today());

        if ($request->has('warehouse_id')) {
            $warehouseId = (int) $request->warehouse_id;
            $inventoryQuery->where('warehouse_id', $warehouseId);
            $lotsQuery->where('warehouse_id', $warehouseId);
            $movementsQuery->where(function ($query) use ($warehouseId): void {
                $query->where('warehouse_id', $warehouseId)
                    ->orWhere('destination_warehouse_id', $warehouseId);
            });
        }

        $lots = $lotsQuery->get(['quantity_available', 'landed_unit_cost']);
        $totalInventoryValue = round((float) $lots->sum(fn (InventoryLot $lot) => (int) $lot->quantity_available * (float) $lot->landed_unit_cost), 2);

        return response()->json([
            'data' => [
                'total_stock' => (int) (clone $inventoryQuery)->sum('quantity_available'),
                'total_inventory_value' => $totalInventoryValue,
                'total_fifo_value' => $totalInventoryValue,
                'low_stock_count' => (int) (clone $inventoryQuery)
                    ->where('quantity_available', '>', 0)
                    ->whereColumn('quantity_available', '<=', 'reorder_point')
                    ->count(),
                'open_lots_count' => $lots->count(),
                'movements_today' => (int) $movementsQuery->count(),
            ],
        ]);
    }

    public function updateLot(UpdateInventoryLotRequest $request, InventoryLot $lot): JsonResponse
    {
        $lot = $this->inventoryLotService->updateAvailableLot(
            $lot,
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'data' => [
                'id' => $lot->id,
                'product_id' => $lot->product_id,
                'variant_id' => $lot->variant_id,
                'warehouse_id' => $lot->warehouse_id,
                'received_at' => $lot->received_at?->toIso8601String(),
                'received_quantity' => (int) $lot->received_quantity,
                'quantity_available' => (int) $lot->quantity_available,
                'unit_cost_base' => round((float) $lot->unit_cost_base, 2),
                'tax_per_unit' => round((float) $lot->tax_per_unit, 2),
                'tax_total' => round((int) $lot->received_quantity * (float) $lot->tax_per_unit, 2),
                'misc_per_unit' => round((float) $lot->misc_per_unit, 2),
                'misc_total' => round((int) $lot->received_quantity * (float) $lot->misc_per_unit, 2),
                'landed_unit_cost' => round((float) $lot->landed_unit_cost, 2),
                'landed_total' => round((int) $lot->received_quantity * (float) $lot->landed_unit_cost, 2),
                'sale_price' => round((float) $lot->sale_price, 2),
                'remaining_value' => round((int) $lot->quantity_available * (float) $lot->landed_unit_cost, 2),
            ],
            'message' => 'Lote de inventario actualizado correctamente',
        ]);
    }

    /**
     * Update inventory for a specific product and warehouse.
     * Supports optional variant_id for variant-level inventory.
     */
    public function update(Request $request, int $productId, int $warehouseId): JsonResponse
    {
        $variantId = $request->input('variant_id');

        if ($variantId) {
            return $this->updateVariantInventory($request, $productId, $warehouseId, (int) $variantId);
        }

        $validated = $request->validate([
            'stock_available' => 'sometimes|integer|min:0',
            'stock_reserved' => 'sometimes|integer|min:0',
            'stock_on_order' => 'sometimes|integer|min:0',
            'reorder_point' => 'sometimes|integer|min:0',
            'target_level' => 'sometimes|integer|min:0',
        ]);

        $inventory = $this->inventoryService->updateInventory($productId, $warehouseId, $validated);

        return response()->json([
            'data' => [
                'id' => $inventory->id,
                'product_id' => $inventory->product_id,
                'warehouse_id' => $inventory->warehouse_id,
                'stock_available' => $inventory->stock_available,
                'stock_reserved' => $inventory->stock_reserved,
                'stock_on_order' => $inventory->stock_on_order,
                'reorder_point' => $inventory->reorder_point,
                'target_level' => $inventory->target_level,
            ],
            'message' => 'Inventario actualizado correctamente',
        ]);
    }

    /**
     * Update variant-level inventory (product_inventory table).
     */
    private function updateVariantInventory(Request $request, int $productId, int $warehouseId, int $variantId): JsonResponse
    {
        $validated = $request->validate([
            'variant_id' => 'required|exists:product_variants,id',
            'stock_available' => 'sometimes|integer|min:0',
            'reorder_point' => 'sometimes|integer|min:0',
            'reorder_quantity' => 'sometimes|integer|min:0',
        ]);

        $variant = \App\Models\ProductVariant::where('id', $variantId)
            ->where('product_id', $productId)
            ->first();

        if (! $variant) {
            return response()->json([
                'message' => 'Variante no encontrada para este producto',
            ], 404);
        }

        $updateData = collect($validated)->only([
            'reorder_point',
            'reorder_quantity',
        ])->filter(fn ($value) => $value !== null)->toArray();

        $inventory = ProductInventory::updateOrCreate(
            [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
            ],
            $updateData
        );

        if (array_key_exists('stock_available', $validated)) {
            app(\App\Services\InventoryFifoService::class)->adjustStockToQuantity([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'quantity' => $validated['stock_available'],
                'reference_type' => 'manual_adjustment',
                'source_type' => 'manual_adjustment',
                'reason' => 'Ajuste manual de inventario',
                'notes' => 'Ajuste manual de inventario',
                'user_id' => $request->user()?->id,
            ]);

            $inventory->refresh();
        }

        return response()->json([
            'data' => [
                'id' => $inventory->id,
                'product_id' => $inventory->product_id,
                'variant_id' => $inventory->variant_id,
                'warehouse_id' => $inventory->warehouse_id,
                'quantity_available' => $inventory->quantity_available,
                'quantity_reserved' => $inventory->quantity_reserved,
                'reorder_point' => $inventory->reorder_point,
                'reorder_quantity' => $inventory->reorder_quantity,
            ],
            'message' => 'Inventario de variante actualizado correctamente',
        ]);
    }

    /**
     * Record a waste/loss adjustment.
     * POST /api/v1/inventory/waste
     */
    public function storeWaste(StoreInventoryRequest $request): JsonResponse
    {
        $validated = $request->validate([
            'variant_id' => 'required|exists:product_variants,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            // Find the variant's product
            $variant = \App\Models\ProductVariant::findOrFail($validated['variant_id']);

            // Check available stock
            $inventory = ProductInventory::where('variant_id', $validated['variant_id'])
                ->where('warehouse_id', $validated['warehouse_id'])
                ->first();

            if (! $inventory || $inventory->quantity_available < $validated['quantity']) {
                return response()->json([
                    'message' => 'Stock insuficiente para registrar la merma',
                    'available' => $inventory?->quantity_available ?? 0,
                ], 422);
            }

            app(\App\Services\InventoryFifoService::class)->consumeStock([
                'movement_type' => 'out',
                'reason' => $validated['reason'],
                'product_id' => $variant->product_id,
                'variant_id' => $validated['variant_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'quantity' => $validated['quantity'],
                'reference_type' => 'waste',
                'notes' => $validated['notes'] ?? null,
                'user_id' => $request->user()?->id,
                'strict' => true,
            ]);

            return response()->json([
                'message' => 'Merma registrada correctamente',
                'remaining' => $inventory->fresh()->quantity_available,
            ], 201);
        });
    }

    /**
     * Get recent waste movements.
     * GET /api/v1/inventory/waste
     */
    public function wasteHistory(Request $request): JsonResponse
    {
        $query = InventoryMovement::with(['variant.brand', 'variant.product', 'warehouse', 'user:id,name'])
            ->where('reference_type', 'waste')
            ->orderByDesc('created_at');

        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        $movements = $query->paginate(20);

        return response()->json([
            'data' => $movements->map(fn ($m) => [
                'id' => $m->id,
                'product_name' => $m->variant?->product?->name ?? 'N/A',
                'sku' => $m->variant?->sku ?? 'N/A',
                'brand' => $m->variant?->brand?->name ?? 'N/A',
                'warehouse' => $m->warehouse?->name,
                'quantity' => $m->quantity,
                'reason' => $m->reason,
                'notes' => $m->notes,
                'user' => $m->user?->name,
                'date' => $m->created_at->toDateTimeString(),
            ]),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'total' => $movements->total(),
            ],
        ]);
    }

    /**
     * Get all warehouses.
     */
    public function warehouses(): JsonResponse
    {
        $warehouses = Warehouse::select('id', 'name', 'code', 'is_active')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $warehouses,
        ]);
    }

    /**
     * Determine stock status based on levels.
     */
    private function inventoryValue(int $productId, ?int $variantId, int $warehouseId): float
    {
        return round((float) InventoryLot::query()
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->where('quantity_available', '>', 0)
            ->get(['quantity_available', 'landed_unit_cost'])
            ->sum(fn (InventoryLot $lot) => (int) $lot->quantity_available * (float) $lot->landed_unit_cost), 2);
    }

    private function openLotsCount(int $productId, ?int $variantId, int $warehouseId): int
    {
        return InventoryLot::query()
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->where('quantity_available', '>', 0)
            ->count();
    }

    private function lastMovementDate(int $productId, ?int $variantId, int $warehouseId, string $movementType): ?string
    {
        $movement = InventoryMovement::query()
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->where('movement_type', $movementType)
            ->latest('created_at')
            ->first();

        return $movement?->created_at?->toIso8601String();
    }

    private function getStockStatus(ProductInventory $inventory): string
    {
        if ($inventory->quantity_available <= 0) {
            return 'out_of_stock';
        }

        if ($inventory->isLowStock()) {
            return 'low_stock';
        }

        if ($inventory->reorder_quantity > 0 && $inventory->quantity_available >= $inventory->reorder_quantity) {
            return 'optimal';
        }

        return 'normal';
    }
}
