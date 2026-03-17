<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Models\ProductInventory;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkOrderController extends Controller
{
    /**
     * GET /api/v1/work-orders
     */
    public function index(Request $request): JsonResponse
    {
        $query = WorkOrder::with(['customer', 'warehouse', 'creator', 'assignee', 'items'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('folio', 'like', "%{$search}%")
                  ->orWhere('vehicle_description', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) ($request->per_page ?? 20), 100);
        $orders  = $query->paginate($perPage);

        return response()->json($orders);
    }

    /**
     * GET /api/v1/work-orders/{id}
     */
    public function show(int $id): JsonResponse
    {
        $workOrder = WorkOrder::with(['customer', 'warehouse', 'creator', 'assignee', 'items.product', 'items.variant'])
            ->findOrFail($id);

        return response()->json($workOrder);
    }

    /**
     * POST /api/v1/work-orders
     *
     * Inventory decrement: uses ProductInventory (table: product_inventory),
     * field quantity_available, filtered by warehouse_id + variant_id (or product_id
     * when no variant). Matches the pattern used in SalesOrderController.
     */
    public function store(StoreWorkOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Stock check (same pattern as SalesOrderController)
        if (! empty($validated['items'])) {
            $stockErrors = [];
            foreach ($validated['items'] as $item) {
                $q = ProductInventory::where('warehouse_id', $item['warehouse_id']);
                if (! empty($item['variant_id'])) {
                    $q->where('variant_id', $item['variant_id']);
                } else {
                    $q->where('product_id', $item['product_id'])->whereNull('variant_id');
                }
                $available = (int) ($q->value('quantity_available') ?? 0);
                if ($item['quantity'] > $available) {
                    $stockErrors[] = "Producto #{$item['product_id']}: solicitado {$item['quantity']}, disponible {$available}";
                }
            }

            if (! empty($stockErrors)) {
                return response()->json([
                    'message' => 'Stock insuficiente para uno o más productos.',
                    'errors'  => $stockErrors,
                ], 422);
            }
        }

        $workOrder = DB::transaction(function () use ($validated, $request) {
            $wo = WorkOrder::create([
                'customer_id'           => $validated['customer_id'] ?? null,
                'warehouse_id'          => $validated['warehouse_id'],
                'created_by'            => $request->user()->id,
                'assigned_to'           => $validated['assigned_to'] ?? null,
                'vehicle_description'   => $validated['vehicle_description'] ?? null,
                'notes'                 => $validated['notes'] ?? null,
                'labour_cost'           => $validated['labour_cost'] ?? 0,
                'estimated_delivery_at' => $validated['estimated_delivery_at'] ?? null,
                'status'                => 'pending',
            ]);

            foreach ($validated['items'] ?? [] as $item) {
                $wo->items()->create([
                    'product_id'  => $item['product_id'],
                    'variant_id'  => $item['variant_id'] ?? null,
                    'warehouse_id'=> $item['warehouse_id'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'notes'       => $item['notes'] ?? null,
                ]);

                // Decrement inventory (quantity_available in product_inventory)
                $invQuery = ProductInventory::where('warehouse_id', $item['warehouse_id']);
                if (! empty($item['variant_id'])) {
                    $invQuery->where('variant_id', $item['variant_id']);
                } else {
                    $invQuery->where('product_id', $item['product_id'])->whereNull('variant_id');
                }
                $invQuery->decrement('quantity_available', $item['quantity']);
            }

            return $wo->load(['items', 'customer', 'warehouse', 'creator', 'assignee']);
        });

        return response()->json([
            'message' => 'Orden de trabajo creada exitosamente',
            'data'    => $workOrder,
        ], 201);
    }

    /**
     * PUT /api/v1/work-orders/{id}
     * Update non-status fields (items are replaced if provided).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);

        $validated = $request->validate([
            'customer_id'           => 'nullable|exists:customers,id',
            'assigned_to'           => 'nullable|exists:users,id',
            'vehicle_description'   => 'nullable|string|max:255',
            'notes'                 => 'nullable|string|max:5000',
            'labour_cost'           => 'nullable|numeric|min:0',
            'estimated_delivery_at' => 'nullable|date',
        ]);

        $workOrder->update($validated);

        return response()->json([
            'message' => 'Orden de trabajo actualizada',
            'data'    => $workOrder->load(['items', 'customer', 'warehouse', 'creator', 'assignee']),
        ]);
    }

    /**
     * POST /api/v1/work-orders/{id}/transition
     * Advance or revert the work order status following allowed transitions.
     */
    public function transition(Request $request, int $id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);

        $request->validate([
            'status' => 'required|in:' . implode(',', WorkOrder::VALID_STATUSES),
        ]);

        $newStatus = $request->status;

        if (! $workOrder->canTransitionTo($newStatus)) {
            return response()->json([
                'message' => "No se puede transitar de '{$workOrder->status}' a '{$newStatus}'.",
            ], 422);
        }

        $workOrder->update(['status' => $newStatus]);

        // If cancelled, restore inventory for all items
        if ($newStatus === 'cancelled') {
            foreach ($workOrder->items as $item) {
                $q = ProductInventory::where('warehouse_id', $item->warehouse_id);
                if ($item->variant_id) {
                    $q->where('variant_id', $item->variant_id);
                } else {
                    $q->where('product_id', $item->product_id)->whereNull('variant_id');
                }
                $q->increment('quantity_available', $item->quantity);
            }
        }

        return response()->json([
            'message' => "Estado actualizado a '{$newStatus}'",
            'data'    => $workOrder->load(['items', 'customer', 'warehouse', 'creator', 'assignee']),
        ]);
    }

    /**
     * DELETE /api/v1/work-orders/{id}
     * Only allowed when status is cancelled or pending.
     */
    public function destroy(int $id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);

        if (! in_array($workOrder->status, ['cancelled', 'pending'])) {
            return response()->json([
                'message' => 'Solo se pueden eliminar órdenes en estado pending o cancelled.',
            ], 422);
        }

        // If pending with items, restore inventory before delete
        if ($workOrder->status === 'pending') {
            foreach ($workOrder->items as $item) {
                $q = ProductInventory::where('warehouse_id', $item->warehouse_id);
                if ($item->variant_id) {
                    $q->where('variant_id', $item->variant_id);
                } else {
                    $q->where('product_id', $item->product_id)->whereNull('variant_id');
                }
                $q->increment('quantity_available', $item->quantity);
            }
        }

        $workOrder->delete();

        return response()->json(['message' => 'Orden de trabajo eliminada']);
    }
}
