<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesReturnController extends Controller
{
    /**
     * GET /api/sales-orders/{orderId}/returns
     * List all returns for a specific sales order.
     */
    public function index(int $orderId): JsonResponse
    {
        $order = SalesOrder::findOrFail($orderId);

        $returns = SalesReturn::where('sales_order_id', $orderId)
            ->with(['items.product', 'items.variant', 'user', 'items.seller'])
            ->orderBy('created_at', 'desc')
            ->get();

        $data = $returns->map(function ($return) {
            $mapped = $return->toArray();
            $mapped['processed_by_name'] = $return->user ? $return->user->name : 'Sistema';
            $mapped['processed_at'] = $return->created_at->toISOString();
            
            $mapped['items'] = $return->items->map(function ($item) {
                $mappedItem = $item->toArray();
                $mappedItem['seller_name'] = $item->seller ? $item->seller->name : null;
                return $mappedItem;
            });

            return $mapped;
        });

        return response()->json($data);
    }

    /**
     * POST /api/sales-orders/{orderId}/returns
     * Create a new return (pending approval) for the given order.
     */
    public function store(Request $request, int $orderId): JsonResponse
    {
        $order = SalesOrder::with('items')->findOrFail($orderId);

        // Only allow returns on confirmed/completed orders
        if (! in_array($order->status, ['confirmed', 'completed'])) {
            return response()->json([
                'message' => 'Solo se pueden devolver órdenes confirmadas o completadas.',
            ], 422);
        }

        // Require an open POS Session to process a return
        $session = \App\Models\PosSession::where('user_id', auth()->id())->where('status', 'open')->first();
        if (!$session) {
            return response()->json([
                'message' => 'Debe tener una sesión de caja abierta para registrar una devolución.',
            ], 403);
        }

        $validated = $request->validate([
            'reason'         => 'nullable|string|max:500',
            'refund_method'  => 'required|string|in:cash,card,transfer,balance',
            'refund_notes'   => 'nullable|string|max:500',
            'items'          => 'required|array|min:1',
            'items.*.sales_order_item_id' => 'required|exists:sales_order_items,id',
            'items.*.quantity'            => 'required|integer|min:1',
            'items.*.user_id'             => 'nullable|exists:users,id',
            'items.*.seller_id'           => 'nullable|exists:users,id',
        ]);

        // Validate quantities don't exceed what was sold (minus already returned)
        $errors = [];
        foreach ($validated['items'] as $returnItem) {
            $orderItem = $order->items->firstWhere('id', $returnItem['sales_order_item_id']);

            if (! $orderItem) {
                $errors[] = "Línea #{$returnItem['sales_order_item_id']} no pertenece a esta orden.";
                continue;
            }

            if ($orderItem->is_custom_line) {
                $errors[] = "La linea '{$orderItem->custom_description}' es miscelanea y no puede devolverse.";
                continue;
            }

            // Sum previously returned quantities for this order item
            $alreadyReturned = \App\Models\SalesReturnItem::whereHas('salesReturn', function ($q) use ($orderId) {
                $q->where('sales_order_id', $orderId)->where('status', 'approved');
            })->where('sales_order_item_id', $returnItem['sales_order_item_id'])
              ->sum('quantity');

            $maxReturnable = $orderItem->quantity - $alreadyReturned;

            if ($returnItem['quantity'] > $maxReturnable) {
                $label = $orderItem->product?->name ?? "Producto #{$orderItem->product_id}";
                $errors[] = "{$label}: máximo devolvible es {$maxReturnable}, solicitado {$returnItem['quantity']}.";
            }
        }

        if (! empty($errors)) {
            return response()->json([
                'message' => 'La cantidad excede lo permitido para devolución.',
                'errors'  => $errors,
            ], 422);
        }

        try {
            $return = DB::transaction(function () use ($order, $validated) {
                $salesReturn = SalesReturn::create([
                    'sales_order_id' => $order->id,
                    'warehouse_id'   => $order->items->first()->warehouse_id ?? 1,
                    'customer_id'    => $order->customer_id,
                    'user_id'        => auth()->id(),
                    'reason'         => $validated['reason'] ?? null,
                    'refund_method'  => $validated['refund_method'],
                    'refund_notes'   => $validated['refund_notes'] ?? null,
                    'status'         => 'pending',
                ]);

                $totalAmount = 0;

                foreach ($validated['items'] as $returnItem) {
                    $orderItem = $order->items->firstWhere('id', $returnItem['sales_order_item_id']);

                    // Calculate unit price after discount
                    $discountPercent = (float) ($orderItem->discount_percent ?? 0);
                    $unitAfterDiscount = round((float) $orderItem->unit_price * (1 - ($discountPercent / 100)), 2);
                    $subtotal = round($unitAfterDiscount * (int) $returnItem['quantity'], 2);

                    $salesReturn->items()->create([
                        'sales_order_item_id' => $orderItem->id,
                        'product_id'          => $orderItem->product_id,
                        'variant_id'          => $orderItem->variant_id,
                        'quantity'            => $returnItem['quantity'],
                        'unit_price'          => $unitAfterDiscount,
                        'subtotal'            => $subtotal,
                        'user_id'             => $returnItem['user_id'] ?? auth()->id(),
                        'seller_id'           => $returnItem['seller_id'] ?? $orderItem->seller_id,
                    ]);

                    $totalAmount += $subtotal;
                }

                $salesReturn->update(['total_amount' => round($totalAmount, 2)]);

                return $salesReturn->load('items.product', 'items.variant');
            });

            return response()->json([
                'message' => 'Devolución creada exitosamente.',
                'data'    => $return,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear la devolución.',
                'error'   => $e->getMessage() . ' en la línea ' . $e->getLine() . ' de ' . basename($e->getFile()),
            ], 500);
        }
    }

    /**
     * POST /api/sales-returns/{id}/approve
     * Approve a pending return (restores inventory, adjusts customer balance).
     */
    public function approve(int $id): JsonResponse
    {
        $return = SalesReturn::with('items', 'salesOrder')->findOrFail($id);

        if ($return->status !== 'pending') {
            return response()->json([
                'message' => 'Esta devolución ya fue procesada.',
            ], 422);
        }

        // Require an open POS Session to approve a return if money is returned directly
        if (in_array($return->refund_method, ['cash', 'card', 'transfer'])) {
            $session = \App\Models\PosSession::where('user_id', auth()->id())->where('status', 'open')->first();
            if (!$session) {
                return response()->json([
                    'message' => 'Debe tener una sesión de caja abierta para aprobar un reembolso directo.',
                ], 403);
            }
        }

        try {
            DB::transaction(function () use ($return) {
                $return->approve();
            });

            return response()->json([
                'message' => 'Devolución aprobada. Inventario y saldo actualizados.',
                'data'    => $return->fresh()->load('items.product'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al aprobar la devolución.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/sales-returns/{id}/reject
     */
    public function reject(int $id): JsonResponse
    {
        $return = SalesReturn::findOrFail($id);

        if (! $return->reject()) {
            return response()->json([
                'message' => 'Esta devolución ya fue procesada.',
            ], 422);
        }

        return response()->json([
            'message' => 'Devolución rechazada.',
            'data'    => $return,
        ]);
    }
}
