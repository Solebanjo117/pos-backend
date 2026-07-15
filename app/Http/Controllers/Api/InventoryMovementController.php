<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInboundMovementRequest;
use App\Http\Requests\StoreOutboundMovementRequest;
use App\Http\Requests\StoreTransferMovementRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Services\InventoryMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryMovementController extends Controller
{
    protected InventoryMovementService $movementService;

    public function __construct(InventoryMovementService $movementService)
    {
        $this->movementService = $movementService;
    }

    /**
     * Get paginated list of movements with filters
     */
    public function index(Request $request): JsonResponse
    {
        $warehouseId = $request->input('warehouse_id');
        if (! $request->user()->isAdmin()) {
            $warehouseId = $request->user()->getEffectiveWarehouseId();
            abort_if($warehouseId === null, 403, 'No tiene un almacen autorizado.');
        }

        $filters = [
            'product_id' => $request->input('product_id'),
            'warehouse_id' => $warehouseId,
            'movement_type' => $request->input('movement_type'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'search' => $request->input('search'),
            'order_by' => $request->input('order_by', 'created_at'),
            'order_direction' => $request->input('order_direction', 'desc'),
        ];

        $perPage = $request->input('per_page', 20);
        $movements = $this->movementService->getMovements($filters, $perPage);

        return response()->json([
            'data' => InventoryMovementResource::collection($movements->items()),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
            ],
        ]);
    }

    /**
     * Get movement by ID
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $movement = $this->movementService->getMovementById($id);
        $this->assertWarehouseAccess($request, $movement->warehouse_id, $movement->destination_warehouse_id);

        return response()->json([
            'data' => new InventoryMovementResource($movement),
        ]);
    }

    /**
     * Record inbound movement
     */
    public function storeInbound(StoreInboundMovementRequest $request): JsonResponse
    {
        try {
            $movement = $this->movementService->recordInbound($request->validated());

            return response()->json([
                'message' => 'Entrada de inventario registrada exitosamente.',
                'data' => new InventoryMovementResource($movement),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Record bulk inbound movements
     */
    public function storeBulkInbound(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.warehouse_id' => 'required|exists:warehouses,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.supplier_id' => 'nullable|integer|exists:suppliers,id',
        ]);

        try {
            $movements = $this->movementService->recordBulkInbound($validated['items']);

            return response()->json([
                'message' => 'Entrada masiva de inventario registrada exitosamente.',
                'data' => InventoryMovementResource::collection($movements),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Record bulk outbound movement
     */
    public function storeBulkOutbound(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.warehouse_id' => 'required|exists:warehouses,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.product_batch_id' => 'nullable|exists:product_batches,id',
        ]);

        try {
            $movements = $this->movementService->recordBulkOutbound($validated['items']);

            return response()->json([
                'message' => 'Salida masiva de inventario registrada exitosamente.',
                'data' => InventoryMovementResource::collection($movements),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Record outbound movement
     */
    public function storeOutbound(StoreOutboundMovementRequest $request): JsonResponse
    {
        try {
            $movement = $this->movementService->recordOutbound($request->validated());

            return response()->json([
                'message' => 'Salida de inventario registrada exitosamente.',
                'data' => new InventoryMovementResource($movement),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Record transfer between warehouses
     */
    public function storeTransfer(StoreTransferMovementRequest $request): JsonResponse
    {
        try {
            $movement = $this->movementService->recordTransfer($request->validated());

            return response()->json([
                'message' => 'Transferencia entre almacenes registrada exitosamente.',
                'data' => new InventoryMovementResource($movement),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get movements by product
     */
    public function byProduct(Request $request, int $productId): JsonResponse
    {
        $filters = [
            'movement_type' => $request->input('movement_type'),
            'warehouse_id' => $request->input('warehouse_id'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        $perPage = $request->input('per_page', 20);
        $movements = $this->movementService->getMovementsByProduct($productId, $filters, $perPage);

        return response()->json([
            'data' => InventoryMovementResource::collection($movements->items()),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
            ],
        ]);
    }

    /**
     * Get movements by warehouse
     */
    public function byWarehouse(Request $request, int $warehouseId): JsonResponse
    {
        $filters = [
            'product_id' => $request->input('product_id'),
            'movement_type' => $request->input('movement_type'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        $perPage = $request->input('per_page', 20);
        $movements = $this->movementService->getMovementsByWarehouse($warehouseId, $filters, $perPage);

        return response()->json([
            'data' => InventoryMovementResource::collection($movements->items()),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
            ],
        ]);
    }

    /**
     * Get related movements
     */
    public function related(Request $request, int $id): JsonResponse
    {
        $movement = $this->movementService->getMovementById($id);
        $this->assertWarehouseAccess($request, $movement->warehouse_id, $movement->destination_warehouse_id);
        $movements = $this->movementService->getRelatedMovements($id);

        return response()->json([
            'data' => InventoryMovementResource::collection($movements),
        ]);
    }

    private function assertWarehouseAccess(Request $request, ?int $warehouseId, ?int $destinationWarehouseId = null): void
    {
        $user = $request->user();
        if ($user->isAdmin()) {
            return;
        }

        $allowed = ($warehouseId !== null && $user->hasWarehouseAccess($warehouseId))
            || ($destinationWarehouseId !== null && $user->hasWarehouseAccess($destinationWarehouseId));

        abort_unless($allowed, 403, 'No tiene acceso a este movimiento de inventario.');
    }
}
