<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PosCheckoutRequest;
use App\Services\SalesOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\ProductVariant;
use App\Models\Inventory;

class SalesOrderController extends Controller
{
    protected SalesOrderService $salesOrderService;

    public function __construct(SalesOrderService $salesOrderService)
    {
        $this->salesOrderService = $salesOrderService;
    }

    /**
     * GET /api/sales-orders
     * List all sales orders with filters
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'search',
                'status',
                'payment_status',
                'customer_id',
                'warehouse_id',
                'date_from',
                'date_to',
                'sort_by',
                'sort_direction',
                'per_page',
            ]);

            $orders = $this->salesOrderService->getAll($filters);

            return response()->json($orders);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener órdenes',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/sales-orders/{id}
     * Get single sales order
     */
    public function show(int $id): JsonResponse
    {
        try {
            $order = $this->salesOrderService->getById($id);
            
            return response()->json($order);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Orden no encontrada',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * POST /api/sales-orders
     * Create new sales order
     */
    public function store(PosCheckoutRequest $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id'           => 'nullable|integer|min:1',
                'customer_id'       => 'nullable|exists:customers,id',
                'date'              => 'nullable|date',
                'status'            => 'nullable|in:draft,pending,confirmed',
                'source_quote_id'   => 'nullable|exists:sales_quotes,id',
                'discount_amount'   => 'nullable|numeric|min:0',
                'shipping_cost'     => 'nullable|numeric|min:0',
                'payment_method'    => 'nullable|in:cash,card,transfer,credit,multiple',
                'payment_status'    => 'nullable|in:pending,partial,paid,refunded',
                'initial_payment'   => 'nullable|numeric|min:0',
                'notes'             => 'nullable|string|max:5000',
                'internal_notes'    => 'nullable|string|max:5000',
                'audit_logs'        => 'nullable|array',
                'payments'          => 'nullable|array',
                'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
                'payments.*.method' => 'required_with:payments|in:cash,card,transfer,credit,visa,mastercard,amex,clave,yappy,transferencia,notaCredito,creditoCliente,store_credit',
                'payments.*.notes'  => 'nullable|string',
                'items'             => 'nullable|array',
                'items.*.product_id'       => 'required|exists:products,id',
                'items.*.variant_id'       => 'nullable|exists:product_variants,id',
                'items.*.warehouse_id'     => 'required|exists:warehouses,id',
                'items.*.quantity'         => 'required|integer|min:1',
                'items.*.unit_price'       => 'required|numeric|min:0',
                'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'items.*.discount_amount'  => 'nullable|numeric|min:0',
                'items.*.tax_percent'      => 'nullable|numeric|min:0|max:100',
                'items.*.seller_id'        => 'nullable|exists:users,id',
            ]);

            // Map specific payment types to generic database types
            $paymentTypeMap = [
                'visa'            => 'card',
                'mastercard'      => 'card',
                'amex'            => 'card',
                'clave'           => 'card',
                'yappy'           => 'transfer',
                'transferencia'   => 'transfer',
                'notaCredito'     => 'credit',
                'creditoCliente'  => 'credit',
            ];

            if (!empty($validated['payments'])) {
                foreach ($validated['payments'] as &$payment) {
                    $originalMethod = $payment['method'];
                    if (isset($paymentTypeMap[$originalMethod])) {
                        $payment['method'] = $paymentTypeMap[$originalMethod];
                        // Preserve the specific type in notes for reports
                        $payment['notes'] = ($payment['notes'] ?? 'Pago POS') . " [{$originalMethod}]";
                    }
                }
                unset($payment); // break reference
            }

            // ── Defensive stock check before creating the order ──
            if (! empty($validated['items'])) {
                $allowNegative = \App\Models\Setting::get('allow_negative_inventory', false);

                if (! $allowNegative) {
                    $stockErrors = [];
                    foreach ($validated['items'] as $item) {
                        // Look up stock in product_inventory using the correct columns
                        $query = \App\Models\ProductInventory::where('warehouse_id', $item['warehouse_id']);

                        if (! empty($item['variant_id'])) {
                            $query->where('variant_id', $item['variant_id']);
                        } else {
                            $query->where('product_id', $item['product_id'])
                                  ->whereNull('variant_id');
                        }

                        $available = (int) ($query->value('quantity_available') ?? 0);

                        if ($item['quantity'] > $available) {
                            if (! empty($item['variant_id'])) {
                                $variant = \App\Models\ProductVariant::find($item['variant_id']);
                                $label = $variant?->sku ?? "Variante #{$item['variant_id']}";
                            } else {
                                $product = \App\Models\Product::find($item['product_id']);
                                $label = $product?->name ?? "Producto #{$item['product_id']}";
                            }
                            $stockErrors[] = "{$label}: solicitado {$item['quantity']}, disponible {$available}";
                        }
                    }

                    if (! empty($stockErrors)) {
                        return response()->json([
                            'message' => 'Stock insuficiente para uno o más productos.',
                            'errors'  => $stockErrors,
                        ], 422);
                    }
                } // end if (! $allowNegative)
            } // end if (! empty($validated['items']))

            // ── Enforce price override permission (R05) ──
            // Only admin and manager roles can override unit_price
            $userRole = $request->user()->role ?? '';
            $canOverridePrice = in_array($userRole, ['admin', 'manager']);

            if (!$canOverridePrice && !empty($validated['items'])) {
                foreach ($validated['items'] as &$item) {
                    // Get system price for this product/variant
                    $pricingQuery = \App\Models\ProductPricing::query();
                    if (!empty($item['variant_id'])) {
                        $pricingQuery->where('variant_id', $item['variant_id']);
                    } else {
                        $pricingQuery->where('product_id', $item['product_id'])->whereNull('variant_id');
                    }
                    $systemPrice = $pricingQuery->value('base_price');
                    if ($systemPrice !== null) {
                        $item['unit_price'] = (float) $systemPrice;
                    }
                }
                unset($item); // break reference
            }

            // ── Credit limit check ──
            $hasCredit = false;
            $creditAmount = 0;
            if (isset($validated['payments'])) {
                foreach ($validated['payments'] as $p) {
                    if (($p['method'] ?? '') === 'credit') {
                        $hasCredit = true;
                        $creditAmount += (float)$p['amount'];
                    }
                }
            } elseif (($validated['payment_method'] ?? null) === 'credit') {
                $hasCredit = true;
            }

            if (
                $hasCredit
                && ! empty($validated['customer_id'])
            ) {
                $customer = \App\Models\Customer::find($validated['customer_id']);

                if ($customer && $customer->credit_limit > 0) {
                    $creditAvailable = max(0, $customer->credit_limit - $customer->current_balance);

                    $requiredCredit = $creditAmount > 0 ? $creditAmount : null;

                    if ($requiredCredit === null) {
                        // Estimate order total from items
                        $estimatedTotal = 0;
                        foreach ($validated['items'] ?? [] as $item) {
                            $lineSubtotal = ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0);
                            $lineDiscount = $lineSubtotal * (($item['discount_percent'] ?? 0) / 100);
                            $lineAfterDisc = $lineSubtotal - $lineDiscount;
                            $lineTax = $lineAfterDisc * (($item['tax_percent'] ?? 0) / 100);
                            $estimatedTotal += $lineAfterDisc + $lineTax;
                        }
                        $estimatedTotal += ($validated['shipping_cost'] ?? 0) - ($validated['discount_amount'] ?? 0);
                        $requiredCredit = $estimatedTotal;
                    }

                    if ($requiredCredit > $creditAvailable) {
                        return response()->json([
                            'message' => "El crédito requerido (\$" . number_format($requiredCredit, 2) . ") excede el crédito disponible del cliente (\$" . number_format($creditAvailable, 2) . "). Límite: \$" . number_format($customer->credit_limit, 2) . ", Saldo actual: \$" . number_format($customer->current_balance, 2) . ".",
                        ], 422);
                    }
                }
            }

            // ── Wrap creation in a DB transaction ──
            $order = DB::transaction(function () use ($validated) {
                return $this->salesOrderService->create($validated);
            });

            return response()->json([
                'message' => 'Orden creada exitosamente',
                'data'    => $order,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'message' => 'Error de base de datos',
                'error'   => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear orden',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/sales-orders/{id}
     * Update existing sales order
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'customer_id' => 'nullable|exists:customers,id',
                'date' => 'nullable|date',
                'discount_amount' => 'nullable|numeric|min:0',
                'shipping_cost' => 'nullable|numeric|min:0',
                'payment_method' => 'nullable|in:cash,card,transfer,credit,multiple',
                'notes' => 'nullable|string',
                'internal_notes' => 'nullable|string',
                'items' => 'nullable|array',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.variant_id' => 'nullable|exists:product_variants,id',
                'items.*.warehouse_id' => 'required|exists:warehouses,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.unit_price' => 'required|numeric|min:0',
                'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'items.*.discount_amount' => 'nullable|numeric|min:0',
                'items.*.tax_percent' => 'nullable|numeric|min:0|max:100',
                'items.*.seller_id'  => 'nullable|exists:users,id',
            ]);

            // ── Enforce price override permission (R05) ──
            $userRole = $request->user()->role ?? '';
            $canOverridePrice = in_array($userRole, ['admin', 'manager']);

            if (!$canOverridePrice && !empty($validated['items'])) {
                foreach ($validated['items'] as &$item) {
                    $pricingQuery = \App\Models\ProductPricing::query();
                    if (!empty($item['variant_id'])) {
                        $pricingQuery->where('variant_id', $item['variant_id']);
                    } else {
                        $pricingQuery->where('product_id', $item['product_id'])->whereNull('variant_id');
                    }
                    $systemPrice = $pricingQuery->value('base_price');
                    if ($systemPrice !== null) {
                        $item['unit_price'] = (float) $systemPrice;
                    }
                }
                unset($item);
            }

            $order = $this->salesOrderService->update($id, $validated);

            return response()->json([
                'message' => 'Orden actualizada exitosamente',
                'data' => $order,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar orden',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * DELETE /api/sales-orders/{id}
     * Delete sales order
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->salesOrderService->delete($id);

            return response()->json([
                'message' => 'Orden eliminada exitosamente',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar orden',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/sales-orders/{id}/confirm
     * Confirm order and deduct inventory
     */
    public function confirm(int $id): JsonResponse
    {
        try {
            $order = $this->salesOrderService->confirmOrder($id);

            return response()->json([
                'message' => 'Orden confirmada exitosamente',
                'data' => $order,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al confirmar orden',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/sales-orders/{id}/complete
     * Mark order as completed
     */
    public function complete(int $id): JsonResponse
    {
        try {
            $order = $this->salesOrderService->completeOrder($id);

            return response()->json([
                'message' => 'Orden completada exitosamente',
                'data' => $order,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al completar orden',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/sales-orders/{id}/cancel
     * Cancel order and revert inventory
     */
    public function cancel(int $id): JsonResponse
    {
        try {
            $order = $this->salesOrderService->cancelOrder($id);

            return response()->json([
                'message' => 'Orden cancelada exitosamente',
                'data' => $order,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al cancelar orden',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/sales-orders/{id}/items
     * Add item to order
     */
    public function addItem(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'product_id' => 'required|exists:products,id',
                'variant_id' => 'nullable|exists:product_variants,id',
                'warehouse_id' => 'required|exists:warehouses,id',
                'quantity' => 'required|integer|min:1',
                'unit_price' => 'required|numeric|min:0',
                'discount_percent' => 'nullable|numeric|min:0|max:100',
                'discount_amount' => 'nullable|numeric|min:0',
                'tax_percent' => 'nullable|numeric|min:0|max:100',
                'seller_id' => 'nullable|exists:users,id',
            ]);

            $item = $this->salesOrderService->addItem($id, $validated);

            return response()->json([
                'message' => 'Item agregado exitosamente',
                'data' => $item,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al agregar item',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * PUT /api/sales-orders/items/{itemId}
     * Update order item
     */
    public function updateItem(Request $request, int $itemId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'quantity' => 'nullable|integer|min:1',
                'unit_price' => 'nullable|numeric|min:0',
                'discount_percent' => 'nullable|numeric|min:0|max:100',
                'discount_amount' => 'nullable|numeric|min:0',
                'tax_percent' => 'nullable|numeric|min:0|max:100',
            ]);

            $item = $this->salesOrderService->updateItem($itemId, $validated);

            return response()->json([
                'message' => 'Item actualizado exitosamente',
                'data' => $item,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar item',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * DELETE /api/sales-orders/items/{itemId}
     * Remove item from order
     */
    public function removeItem(int $itemId): JsonResponse
    {
        try {
            $this->salesOrderService->removeItem($itemId);

            return response()->json([
                'message' => 'Item eliminado exitosamente',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar item',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * GET /api/sales-orders/statistics
     * Get sales statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['date_from', 'date_to', 'warehouse_id']);
            $stats = $this->salesOrderService->getStatistics($filters);

            return response()->json($stats);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener estadísticas',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PATCH /api/sales-orders/{id}/add-items
     * Update existing order, adding missing items, updating quantities, generating payments and inventory difference.
     */
    public function addItemsToExistingOrder(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'items' => 'required|array',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.variant_id' => 'nullable|exists:product_variants,id',
                'items.*.warehouse_id' => 'required|exists:warehouses,id',
                'items.*.qty' => 'required|integer|min:1',
                'items.*.price' => 'required|numeric|min:0',
                'items.*.seller_id' => 'nullable|exists:users,id',
                'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'items.*.tax_percent' => 'nullable|numeric|min:0|max:100',
                'payment' => 'nullable|array',
                'payment.*.method' => 'required|string',
                'payment.*.amount' => 'required|numeric|min:0',
                'payment.*.notes' => 'nullable|string',
            ]);

            $order = \App\Models\SalesOrder::findOrFail($id);

            DB::transaction(function() use ($validated, $order) {
                foreach ($validated['items'] as $item) {
                    $query = $order->items()->where('product_id', $item['product_id']);
                    if (!empty($item['variant_id'])) {
                        $query->where('variant_id', $item['variant_id']);
                    } else {
                        $query->whereNull('variant_id');
                    }
                    
                    $existing = $query->first();

                    $diffQty = $item['qty'];
                    
                    if ($existing) {
                        $diffQty = $item['qty'] - $existing->quantity;
                        if ($diffQty > 0) {
                            $existing->update(['quantity' => $item['qty']]);
                            // Deduct diff from inventory
                            $inventory = \App\Models\ProductInventory::where('warehouse_id', $item['warehouse_id'])
                                ->where('product_id', $item['product_id'])
                                ->when(!empty($item['variant_id']), function($q) use ($item) {
                                    $q->where('variant_id', $item['variant_id']);
                                }, function($q) {
                                    $q->whereNull('variant_id');
                                })->first();
                                
                            if ($inventory) {
                                $inventory->decrement('quantity_available', $diffQty);
                            }
                        }
                    } else {
                        // New item
                        $subtotal = $item['qty'] * $item['price'];
                        $discount = $subtotal * (($item['discount_percent'] ?? 0) / 100);
                        $tax = ($subtotal - $discount) * (($item['tax_percent'] ?? 0) / 100);
                        
                        $pricing = \App\Models\ProductPricing::where('product_id', $item['product_id'])
                            ->when(!empty($item['variant_id']), function($q) use ($item) {
                                $q->where('variant_id', $item['variant_id']);
                            }, function($q) {
                                $q->whereNull('variant_id');
                            })->first();
                            
                        $order->items()->create([
                            'product_id' => $item['product_id'],
                            'variant_id' => $item['variant_id'],
                            'warehouse_id' => $item['warehouse_id'],
                            'quantity' => $item['qty'],
                            'unit_price' => $item['price'],
                            'unit_cost' => $pricing ? $pricing->cost : 0,
                            'discount_percent' => $item['discount_percent'] ?? 0,
                            'tax_percent' => $item['tax_percent'] ?? 0,
                            'seller_id' => $item['seller_id'] ?? null,
                            'subtotal' => $subtotal,
                            'discount_amount' => $discount,
                            'tax_amount' => $tax,
                            'total' => $subtotal - $discount + $tax,
                        ]);

                        // Deduct all from inventory
                        $inventory = \App\Models\ProductInventory::where('warehouse_id', $item['warehouse_id'])
                            ->where('product_id', $item['product_id'])
                            ->when(!empty($item['variant_id']), function($q) use ($item) {
                                $q->where('variant_id', $item['variant_id']);
                            }, function($q) {
                                $q->whereNull('variant_id');
                            })->first();
                            
                        if ($inventory) {
                            $inventory->decrement('quantity_available', $item['qty']);
                        }
                    }
                }

                // Recalculate order total properly
                $orderTotal = 0;
                $orderTax = 0;
                $orderDiscount = 0;
                $orderSubtotal = 0;

                foreach ($order->items()->get() as $dbItem) {
                    $itemSubtotal = $dbItem->quantity * $dbItem->unit_price;
                    $itemDiscount = $itemSubtotal * ($dbItem->discount_percent / 100);
                    $itemTax = ($itemSubtotal - $itemDiscount) * ($dbItem->tax_percent / 100);
                    
                    $orderSubtotal += $itemSubtotal;
                    $orderDiscount += $itemDiscount;
                    $orderTax += $itemTax;
                    $orderTotal += ($itemSubtotal - $itemDiscount + $itemTax);
                    
                    $dbItem->update([
                        'subtotal' => $itemSubtotal,
                        'discount_amount' => $itemDiscount,
                        'tax_amount' => $itemTax,
                        'total' => $itemSubtotal - $itemDiscount + $itemTax
                    ]);
                }

                $finalTotal = $orderTotal + $order->shipping_cost - $order->discount_amount;
                
                $logs = $order->audit_logs ?? [];
                $logs[] = [
                    'action' => 'order_modified',
                    'metadata' => [
                        'items_count' => count($validated['items'] ?? []),
                        'total_diff'  => $finalTotal - $order->total,
                        'user_id'     => auth()->id(),
                        'user_name'   => auth()->user() ? auth()->user()->name : 'Sistema'
                    ],
                    'timestamp' => now()->toISOString()
                ];
                
                $order->update([
                    'total' => $finalTotal,
                    'tax_amount' => $orderTax,
                    'subtotal' => $orderSubtotal,
                    'audit_logs' => $logs
                ]);

                // Register payments
                if (!empty($validated['payment'])) {
                    foreach ($validated['payment'] as $payment) {
                        $order->addPayment(
                            amount: (float)$payment['amount'],
                            method: $payment['method'],
                            notes: $payment['notes'] ?? 'Cobro por diferencia (Modificación de Orden)'
                        );

                        // Reduce customer debt / apply store credit immediately (addPayment handles normal payments but NOT credit)
                        // Actually, addPayment inside SalesOrder DOES reduce customer debt automatically for normal methods!
                        // BUT for store_credit, it increases balance. For credit, it's skipped.
                        // Since `confirm()` already ran in the past, we need to manually adjust for `credit`.
                        if ($payment['method'] === 'credit' && $order->customer_id) {
                            $customer = \App\Models\Customer::find($order->customer_id);
                            if ($customer) {
                                $customer->current_balance += $payment['amount'];
                                $customer->save();
                            }
                        }
                    }
                    $totalPaid = $order->payments()->sum('amount');
                    if ($totalPaid >= $order->total) {
                        $order->update(['payment_status' => 'paid']);
                    } else if ($totalPaid > 0) {
                        $order->update(['payment_status' => 'partial']);
                    }
                }
            });

            return response()->json([
                'message' => 'Items added and order updated successfully.',
                'data' => clone $order,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al agregar ítems a la orden existente.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
