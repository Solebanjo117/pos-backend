<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWarehouseRequest;
use App\Http\Requests\UpdateWarehouseRequest;
use App\Http\Resources\WarehouseResource;
use App\Services\WarehouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    protected WarehouseService $warehouseService;

    public function __construct(WarehouseService $warehouseService)
    {
        $this->warehouseService = $warehouseService;
    }

    /**
     * Get paginated list of warehouses
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'order_by' => $request->input('order_by', 'name'),
            'order_direction' => $request->input('order_direction', 'asc'),
        ];

        $perPage = $request->input('per_page', 20);
        $warehouses = $this->warehouseService->getWarehouses($filters, $perPage);

        return response()->json([
            'data' => WarehouseResource::collection($warehouses->items()),
            'meta' => [
                'current_page' => $warehouses->currentPage(),
                'last_page' => $warehouses->lastPage(),
                'per_page' => $warehouses->perPage(),
                'total' => $warehouses->total(),
            ],
        ]);
    }

    /**
     * Get warehouse by ID with statistics
     */
    public function show(int $id): JsonResponse
    {
        $warehouse = $this->warehouseService->getWarehouseById($id);
        
        return response()->json([
            'data' => new WarehouseResource($warehouse),
        ]);
    }

    /**
     * Create new warehouse
     */
    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        $warehouse = $this->warehouseService->createWarehouse($request->validated());

        return response()->json([
            'message' => 'Almacén creado exitosamente.',
            'data' => new WarehouseResource($warehouse),
        ], 201);
    }

    /**
     * Update warehouse
     */
    public function update(UpdateWarehouseRequest $request, int $id): JsonResponse
    {
        $warehouse = $this->warehouseService->updateWarehouse($id, $request->validated());

        return response()->json([
            'message' => 'Almacén actualizado exitosamente.',
            'data' => new WarehouseResource($warehouse),
        ]);
    }

    /**
     * Delete warehouse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->warehouseService->deleteWarehouse($id);

            return response()->json([
                'message' => 'Almacén eliminado exitosamente.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get inventory for specific warehouse
     */
    public function inventory(Request $request, int $id): JsonResponse
    {
        $filters = [
            'search' => $request->input('search'),
            'low_stock' => $request->boolean('low_stock'),
            'out_of_stock' => $request->boolean('out_of_stock'),
            'order_by' => $request->input('order_by', 'updated_at'),
            'order_direction' => $request->input('order_direction', 'desc'),
        ];

        $perPage = $request->input('per_page', 20);
        $inventory = $this->warehouseService->getWarehouseInventory($id, $filters, $perPage);

        // Transform inventory items to include product info in the format frontend expects
        $transformedItems = collect($inventory->items())->map(function ($item) {
            $variant = $item->variant;
            $product = $item->product;

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'quantity_available' => $item->quantity_available,
                'quantity_reserved' => $item->quantity_reserved ?? 0,
                'reorder_point' => $item->reorder_point ?? 0,
                'product' => [
                    'name' => $product?->name ?? 'N/A',
                    'sku' => $variant?->sku ?? $product?->sku_base ?? 'N/A',
                    'brand' => [
                        'name' => $variant?->brand?->name ?? null,
                    ],
                ],
            ];
        });

        return response()->json([
            'data' => $transformedItems,
            'meta' => [
                'current_page' => $inventory->currentPage(),
                'last_page' => $inventory->lastPage(),
                'per_page' => $inventory->perPage(),
                'total' => $inventory->total(),
            ],
        ]);
    }

    /**
     * Get all warehouses for dropdown
     */
    public function all(): JsonResponse
    {
        $warehouses = $this->warehouseService->getAllWarehouses();

        return response()->json([
            'data' => $warehouses,
        ]);
    }
}
