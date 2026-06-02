<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexWorkOrderRequest;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Requests\TransitionWorkOrderRequest;
use App\Http\Requests\UpdateWorkOrderRequest;
use App\Models\ProductInventory;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class WorkOrderController extends Controller
{
    public function index(IndexWorkOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $query = WorkOrder::with($this->workOrderRelations())->latest();

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['warehouse_id'])) {
            $query->where('warehouse_id', $validated['warehouse_id']);
        }

        if (! empty($validated['customer_id'])) {
            $query->where('customer_id', $validated['customer_id']);
        }

        if (! empty($validated['customer_vehicle_id'])) {
            $query->where('customer_vehicle_id', $validated['customer_vehicle_id']);
        }

        if (! empty($validated['assigned_to'])) {
            $query->where('assigned_to', $validated['assigned_to']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        if (! empty($validated['search'])) {
            $search = (string) $validated['search'];
            $operator = DB::connection()->getDriverName() === 'sqlite' ? 'like' : 'ilike';
            $query->where(function (Builder $query) use ($operator, $search): void {
                $query->where('folio', $operator, "%{$search}%")
                    ->orWhere('vehicle_description', $operator, "%{$search}%")
                    ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery
                        ->where('name', $operator, "%{$search}%")
                        ->orWhere('code', $operator, "%{$search}%"))
                    ->orWhereHas('customerVehicle', fn (Builder $vehicleQuery) => $vehicleQuery
                        ->where('vin', $operator, "%{$search}%")
                        ->orWhere('label', $operator, "%{$search}%"));
            });
        }

        $summary = $this->workOrderSummary(clone $query);
        $orders = $query->paginate((int) ($validated['per_page'] ?? 20));
        $payload = $orders->toArray();
        $payload['summary'] = $summary;
        $payload['meta'] = [
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
        ];

        return response()->json($payload);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(
            WorkOrder::with($this->workOrderRelations())->findOrFail($id)
        );
    }

    public function store(StoreWorkOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (! empty($validated['items'])) {
            $stockErrors = [];
            foreach ($validated['items'] as $item) {
                $query = ProductInventory::where('warehouse_id', $item['warehouse_id']);
                if (! empty($item['variant_id'])) {
                    $query->where('variant_id', $item['variant_id']);
                } else {
                    $query->where('product_id', $item['product_id'])->whereNull('variant_id');
                }

                $available = (int) ($query->value('quantity_available') ?? 0);
                if ($item['quantity'] > $available) {
                    $stockErrors[] = "Producto #{$item['product_id']}: solicitado {$item['quantity']}, disponible {$available}";
                }
            }

            if (! empty($stockErrors)) {
                return response()->json([
                    'message' => 'Stock insuficiente para uno o más productos.',
                    'errors' => $stockErrors,
                ], 422);
            }
        }

        $workOrder = DB::transaction(function () use ($validated, $request): WorkOrder {
            $workOrder = WorkOrder::create([
                'customer_id' => $validated['customer_id'] ?? null,
                'customer_vehicle_id' => $validated['customer_vehicle_id'] ?? null,
                'warehouse_id' => $validated['warehouse_id'],
                'created_by' => $request->user()->id,
                'assigned_to' => $validated['assigned_to'] ?? null,
                'vehicle_description' => $validated['vehicle_description'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'labour_cost' => $validated['labour_cost'] ?? 0,
                'estimated_delivery_at' => $validated['estimated_delivery_at'] ?? null,
                'status' => 'pending',
            ]);

            foreach ($validated['items'] ?? [] as $item) {
                $workOrderItem = $workOrder->items()->create([
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'warehouse_id' => $item['warehouse_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'notes' => $item['notes'] ?? null,
                ]);

                app(\App\Services\InventoryFifoService::class)->consumeStock([
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'warehouse_id' => $item['warehouse_id'],
                    'quantity' => $item['quantity'],
                    'reference_type' => 'work_order',
                    'reference_id' => $workOrder->id,
                    'reason' => 'work_order_item',
                    'notes' => "Orden de trabajo {$workOrder->folio} item #{$workOrderItem->id}",
                    'user_id' => $request->user()->id,
                    'strict' => true,
                ]);
            }

            return $workOrder->load($this->workOrderRelations());
        });

        return response()->json([
            'message' => 'Orden de trabajo creada exitosamente',
            'data' => $workOrder,
        ], 201);
    }

    public function update(UpdateWorkOrderRequest $request, int $id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);
        $workOrder->update($request->validated());

        return response()->json([
            'message' => 'Orden de trabajo actualizada',
            'data' => $workOrder->load($this->workOrderRelations()),
        ]);
    }

    public function transition(TransitionWorkOrderRequest $request, int $id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);
        $newStatus = $request->validated('status');

        if (! $workOrder->canTransitionTo($newStatus)) {
            return response()->json([
                'message' => "No se puede transitar de '{$workOrder->status}' a '{$newStatus}'.",
            ], 422);
        }

        $workOrder->update(['status' => $newStatus]);

        if ($newStatus === 'cancelled') {
            $movements = \App\Models\InventoryMovement::query()
                ->where('reference_type', 'work_order')
                ->where('reference_id', $workOrder->id)
                ->where('movement_type', 'out')
                ->get();

            foreach ($movements as $movement) {
                app(\App\Services\InventoryFifoService::class)->restoreFromOutboundMovement($movement, [
                    'reference_type' => 'work_order_cancelled',
                    'reference_id' => $workOrder->id,
                    'reason' => 'work_order_cancelled',
                    'notes' => "Cancelacion de orden de trabajo {$workOrder->folio}",
                    'user_id' => $request->user()?->id,
                ]);
            }
        }

        return response()->json([
            'message' => "Estado actualizado a '{$newStatus}'",
            'data' => $workOrder->load($this->workOrderRelations()),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);

        if (! in_array($workOrder->status, ['cancelled', 'pending'], true)) {
            return response()->json([
                'message' => 'Solo se pueden eliminar ordenes en estado pending o cancelled.',
            ], 422);
        }

        if ($workOrder->status === 'pending') {
            $movements = \App\Models\InventoryMovement::query()
                ->where('reference_type', 'work_order')
                ->where('reference_id', $workOrder->id)
                ->where('movement_type', 'out')
                ->get();

            foreach ($movements as $movement) {
                app(\App\Services\InventoryFifoService::class)->restoreFromOutboundMovement($movement, [
                    'reference_type' => 'work_order_deleted',
                    'reference_id' => $workOrder->id,
                    'reason' => 'work_order_deleted',
                    'notes' => "Eliminacion de orden de trabajo {$workOrder->folio}",
                ]);
            }
        }

        $workOrder->delete();

        return response()->json(['message' => 'Orden de trabajo eliminada']);
    }

    /**
     * @return array<int, string>
     */
    private function workOrderRelations(): array
    {
        return [
            'customer:id,code,name,email,phone,current_balance',
            'customerVehicle.model.make',
            'customerVehicle.chassis',
            'customerVehicle.engine',
            'warehouse:id,name,code',
            'creator:id,name',
            'assignee:id,name,email,role',
            'items.product:id,sku_base,name',
            'items.variant:id,sku,brand_id',
            'items.variant.brand:id,name',
            'items.warehouse:id,name',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function workOrderSummary(Builder $query): array
    {
        $orders = $query->get(['id', 'status', 'estimated_delivery_at']);

        return [
            'total' => $orders->count(),
            'pending' => $orders->where('status', 'pending')->count(),
            'in_progress' => $orders->where('status', 'in_progress')->count(),
            'done' => $orders->where('status', 'done')->count(),
            'delivered' => $orders->where('status', 'delivered')->count(),
            'cancelled' => $orders->where('status', 'cancelled')->count(),
            'overdue' => $orders
                ->filter(fn (WorkOrder $order): bool => ! in_array($order->status, ['delivered', 'cancelled'], true)
                    && $order->estimated_delivery_at !== null
                    && $order->estimated_delivery_at->isPast())
                ->count(),
        ];
    }
}
