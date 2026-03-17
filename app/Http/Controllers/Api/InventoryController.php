<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryRequest;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\ProductInventory;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService
    ) {}

    /**
     * Get inventory list with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Inventory::with(['product.defaultVariant.brand', 'warehouse'])
            ->select('inventory.*');

        // Filter by warehouse
        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        // Filter by low stock
        if ($request->boolean('low_stock')) {
            $query->whereColumn('stock_available', '<=', 'reorder_point');
        }

        // Search by product name or SKU
        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('sku_base', 'ilike', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'product_id');
        $sortOrder = $request->get('sort_order', 'asc');
        
        if ($sortBy === 'stock_available' || $sortBy === 'reorder_point') {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min($request->get('per_page', 20), 100);
        $inventory = $query->paginate($perPage);

        return response()->json([
            'data' => $inventory->map(function ($item) {
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name,
                    'product_sku' => $item->product->sku_base,
                    'brand' => $item->product->defaultVariant?->brand?->name ?? 'N/A',
                    'warehouse_id' => $item->warehouse_id,
                    'warehouse_name' => $item->warehouse->name,
                    'stock_available' => $item->stock_available,
                    'stock_reserved' => $item->stock_reserved,
                    'stock_on_order' => $item->stock_on_order,
                    'reorder_point' => $item->reorder_point,
                    'target_level' => $item->target_level,
                    'needs_reorder' => $item->needsReorder(),
                    'status' => $this->getStockStatus($item),
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

    /**
     * Update inventory for a specific product and warehouse.
     */
    public function update(Request $request, int $productId, int $warehouseId): JsonResponse
    {
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
     * Record a waste/loss adjustment.
     * POST /api/v1/inventory/waste
     */
    public function storeWaste(StoreInventoryRequest $request): JsonResponse
    {
        $validated = $request->validate([
            'variant_id'   => 'required|exists:product_variants,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity'     => 'required|integer|min:1',
            'reason'       => 'required|string|max:255',
            'notes'        => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            // Find the variant's product
            $variant = \App\Models\ProductVariant::findOrFail($validated['variant_id']);

            // Check available stock
            $inventory = ProductInventory::where('variant_id', $validated['variant_id'])
                ->where('warehouse_id', $validated['warehouse_id'])
                ->first();

            if (!$inventory || $inventory->quantity_available < $validated['quantity']) {
                return response()->json([
                    'message' => 'Stock insuficiente para registrar la merma',
                    'available' => $inventory?->quantity_available ?? 0,
                ], 422);
            }

            // Deduct stock
            $inventory->decrement('quantity_available', $validated['quantity']);

            // Record movement
            InventoryMovement::create([
                'movement_type' => 'out',
                'reason'        => $validated['reason'],
                'product_id'    => $variant->product_id,
                'variant_id'    => $validated['variant_id'],
                'warehouse_id'  => $validated['warehouse_id'],
                'quantity'      => $validated['quantity'],
                'reference_type' => 'waste',
                'notes'         => $validated['notes'] ?? null,
                'user_id'       => $request->user()?->id,
            ]);

            return response()->json([
                'message'   => 'Merma registrada correctamente',
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
            'data' => $movements->map(fn($m) => [
                'id'           => $m->id,
                'product_name' => $m->variant?->product?->name ?? 'N/A',
                'sku'          => $m->variant?->sku ?? 'N/A',
                'brand'        => $m->variant?->brand?->name ?? 'N/A',
                'warehouse'    => $m->warehouse?->name,
                'quantity'     => $m->quantity,
                'reason'       => $m->reason,
                'notes'        => $m->notes,
                'user'         => $m->user?->name,
                'date'         => $m->created_at->toDateTimeString(),
            ]),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page'    => $movements->lastPage(),
                'total'        => $movements->total(),
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
    private function getStockStatus(Inventory $inventory): string
    {
        if ($inventory->stock_available <= 0) {
            return 'out_of_stock';
        }

        if ($inventory->needsReorder()) {
            return 'low_stock';
        }

        if ($inventory->stock_available >= $inventory->target_level) {
            return 'optimal';
        }

        return 'normal';
    }
}
