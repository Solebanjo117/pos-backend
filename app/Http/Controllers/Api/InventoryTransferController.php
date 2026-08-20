<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryTransfer;
use App\Services\InventoryTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryTransferController extends Controller
{
    public function __construct(
        private InventoryTransferService $transferService
    ) {}

    /**
     * List all transfers, optionally filtered by warehouse.
     */
    public function index(Request $request): JsonResponse
    {
        $query = InventoryTransfer::with(['fromWarehouse:id,name', 'toWarehouse:id,name', 'creator:id,name', 'items'])
            ->orderByDesc('created_at');

        // Filter by warehouse (show transfers FROM or TO the user's current warehouse)
        if ($request->has('warehouse_id')) {
            $wId = $request->input('warehouse_id');
            $query->where(function ($q) use ($wId) {
                $q->where('from_warehouse_id', $wId)
                    ->orWhere('to_warehouse_id', $wId);
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $transfers = $query->paginate($request->input('per_page', 20));

        return response()->json($transfers);
    }

    /**
     * Show a single transfer with all details.
     */
    public function show(InventoryTransfer $inventoryTransfer): JsonResponse
    {
        return response()->json(
            $inventoryTransfer->load([
                'fromWarehouse:id,name',
                'toWarehouse:id,name',
                'creator:id,name',
                'receiver:id,name',
                'items.product:id,name,sku',
                'items.variant:id,sku,name',
            ])
        );
    }

    /**
     * Create a new draft transfer.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'to_warehouse_id' => 'required|exists:warehouses,id|different:from_warehouse_id',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $transfer = $this->transferService->create(
            $request->only(['from_warehouse_id', 'to_warehouse_id', 'notes', 'items']),
            $request->user()->id
        );

        return response()->json([
            'message' => 'Transferencia creada exitosamente.',
            'transfer' => $transfer,
        ], 201);
    }

    /**
     * Dispatch a draft transfer (deduct stock from origin).
     */
    public function dispatch(InventoryTransfer $inventoryTransfer): JsonResponse
    {
        try {
            $transfer = $this->transferService->dispatch($inventoryTransfer);

            return response()->json([
                'message' => 'Transferencia despachada. Mercancía en tránsito.',
                'transfer' => $transfer,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Receive an in-transit transfer (add stock to destination).
     */
    public function receive(Request $request, InventoryTransfer $inventoryTransfer): JsonResponse
    {
        try {
            $transfer = $this->transferService->receive($inventoryTransfer, $request->user()->id);

            return response()->json([
                'message' => 'Transferencia recibida exitosamente.',
                'transfer' => $transfer,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Cancel a draft transfer.
     */
    public function cancel(InventoryTransfer $inventoryTransfer): JsonResponse
    {
        try {
            $transfer = $this->transferService->cancel($inventoryTransfer);

            return response()->json([
                'message' => 'Transferencia cancelada.',
                'transfer' => $transfer,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
