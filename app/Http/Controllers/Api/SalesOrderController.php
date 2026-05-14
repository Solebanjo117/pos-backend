<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PosCheckoutRequest;
use App\Models\Inventory;
use App\Models\PaymentMethod;
use App\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
    public function show(string $id): JsonResponse
    {
        try {
            $orderId = (int) $id;

            // Validate ID is positive integer
            if ($orderId <= 0) {
                return response()->json([
                    'message' => 'ID de orden inválido',
                    'error' => 'El ID debe ser un número positivo',
                ], 400);
            }

            $order = $this->salesOrderService->getById($orderId);

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
            $this->normalizeTaxPercentPayload($request);

            // Get all active payment method codes dynamically
            $validPaymentMethods = PaymentMethod::active()->pluck('code')->toArray();

            $validated = $request->validate([
                'user_id' => 'nullable|integer|min:1',
                'customer_id' => 'nullable|exists:customers,id',
                'date' => 'nullable|date',
                'status' => 'nullable|in:draft,pending,confirmed',
                'source_quote_id' => 'nullable|exists:sales_quotes,id',
                'discount_amount' => 'nullable|numeric|min:0',
                'shipping_cost' => 'nullable|numeric|min:0',
                'payment_method' => 'nullable|in:cash,card,transfer,credit,multiple',
                'payment_status' => 'nullable|in:pending,partial,paid,refunded',
                'initial_payment' => 'nullable|numeric|min:0',
                'notes' => 'nullable|string|max:5000',
                'internal_notes' => 'nullable|string|max:5000',
                'audit_logs' => 'nullable|array',
                'payments' => 'nullable|array',
                'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
                'payments.*.method' => ['required_with:payments', Rule::in($validPaymentMethods)],
                'payments.*.notes' => 'nullable|string',
                'items' => 'nullable|array',
                'items.*.product_id' => 'nullable|exists:products,id',
                'items.*.variant_id' => 'nullable|exists:product_variants,id',
                'items.*.warehouse_id' => 'required|exists:warehouses,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.unit_price' => 'required|numeric|min:0',
                'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'items.*.discount_amount' => 'nullable|numeric|min:0',
                'items.*.tax_percent' => 'nullable|integer|min:0|max:100',
                'items.*.seller_id' => 'nullable|exists:users,id',
                'items.*.is_custom_line' => 'nullable|boolean',
                'items.*.custom_description' => 'nullable|string|max:255',
            ]);

            if (! empty($validated['items'])) {
                $validated['items'] = $this->normalizeCustomLineItems($validated['items']);
            }

            // Map specific payment types to generic database types
            $paymentTypeMap = [
                'visa' => 'card',
                'mastercard' => 'card',
                'amex' => 'card',
                'clave' => 'card',
                'yappy' => 'transfer',
                'transferencia' => 'transfer',
                'notaCredito' => 'credit',
                'creditoCliente' => 'credit',
            ];

            if (! empty($validated['payments'])) {
                foreach ($validated['payments'] as &$payment) {
                    $originalMethod = $payment['method'];
                    if (isset($paymentTypeMap[$originalMethod])) {
                        $payment['method'] = $paymentTypeMap[$originalMethod];
                        // Preserve the specific type in notes for reports
                        $payment['notes'] = ($payment['notes'] ?? 'Pago POS')." [{$originalMethod}]";
                    }
                }
                unset($payment); // break reference
            }

            // ── Defensive stock check — collect warnings only, do NOT block ──
            $stockWarnings = [];
            if (! empty($validated['items'])) {
                foreach ($validated['items'] as $item) {
                    if (! empty($item['is_custom_line'])) {
                        continue;
                    }

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
                        $stockWarnings[] = [
                            'type' => 'stock_shortage',
                            'product_id' => $item['product_id'] ?? null,
                            'variant_id' => $item['variant_id'] ?? null,
                            'warehouse_id' => $item['warehouse_id'],
                            'product_name' => $label,
                            'requested_quantity' => $item['quantity'],
                            'available_quantity' => $available,
                            'missing_quantity' => $item['quantity'] - $available,
                            'message' => "{$label}: solicitado {$item['quantity']}, disponible {$available}, faltante ".($item['quantity'] - $available),
                        ];
                    }
                }
            } // end stock warning collection

            // ── Enforce price override permission (R05) ──
            // Only admin and manager roles can override unit_price
            $userRole = $request->user()->role ?? '';
            $canOverridePrice = in_array($userRole, ['admin', 'manager']);

            if (! $canOverridePrice && ! empty($validated['items'])) {
                foreach ($validated['items'] as &$item) {
                    if (! empty($item['is_custom_line'])) {
                        continue;
                    }

                    // Get system price for this product/variant
                    $pricingQuery = \App\Models\ProductPricing::query();
                    if (! empty($item['variant_id'])) {
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
                        $creditAmount += (float) $p['amount'];
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
                            'message' => 'El crédito requerido ($'.number_format($requiredCredit, 2).') excede el crédito disponible del cliente ($'.number_format($creditAvailable, 2).'). Límite: $'.number_format($customer->credit_limit, 2).', Saldo actual: $'.number_format($customer->current_balance, 2).'.',
                        ], 422);
                    }
                }
            }

            // ── Wrap creation in a DB transaction ──
            $order = DB::transaction(function () use ($validated) {
                return $this->salesOrderService->create($validated);
            });

            $response = [
                'success' => true,
                'message' => 'Orden creada exitosamente',
                'data' => $order,
            ];

            $allWarnings = array_merge($stockWarnings, $order->stockWarnings);
            if (! empty($allWarnings)) {
                $response['warnings'] = $allWarnings;
            }

            return response()->json($response, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'message' => 'Error de base de datos',
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear orden',
                'error' => $e->getMessage(),
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
            $this->normalizeTaxPercentPayload($request);

            $validated = $request->validate([
                'customer_id' => 'nullable|exists:customers,id',
                'date' => 'nullable|date',
                'discount_amount' => 'nullable|numeric|min:0',
                'shipping_cost' => 'nullable|numeric|min:0',
                'payment_method' => 'nullable|in:cash,card,transfer,credit,multiple',
                'notes' => 'nullable|string',
                'internal_notes' => 'nullable|string',
                'items' => 'nullable|array',
                'items.*.product_id' => 'nullable|exists:products,id',
                'items.*.variant_id' => 'nullable|exists:product_variants,id',
                'items.*.warehouse_id' => 'required|exists:warehouses,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.unit_price' => 'required|numeric|min:0',
                'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'items.*.discount_amount' => 'nullable|numeric|min:0',
                'items.*.tax_percent' => 'nullable|integer|min:0|max:100',
                'items.*.seller_id' => 'nullable|exists:users,id',
                'items.*.is_custom_line' => 'nullable|boolean',
                'items.*.custom_description' => 'nullable|string|max:255',
            ]);

            if (! empty($validated['items'])) {
                $validated['items'] = $this->normalizeCustomLineItems($validated['items']);
            }

            // ── Enforce price override permission (R05) ──
            $userRole = $request->user()->role ?? '';
            $canOverridePrice = in_array($userRole, ['admin', 'manager']);

            if (! $canOverridePrice && ! empty($validated['items'])) {
                foreach ($validated['items'] as &$item) {
                    if (! empty($item['is_custom_line'])) {
                        continue;
                    }

                    $pricingQuery = \App\Models\ProductPricing::query();
                    if (! empty($item['variant_id'])) {
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

            $response = [
                'success' => true,
                'message' => 'Orden confirmada exitosamente',
                'data' => $order,
            ];

            if (! empty($order->stockWarnings)) {
                $response['warnings'] = $order->stockWarnings;
            }

            return response()->json($response);
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
            $this->normalizeTaxPercentPayload($request);

            $validated = $request->validate([
                'product_id' => 'nullable|exists:products,id',
                'variant_id' => 'nullable|exists:product_variants,id',
                'warehouse_id' => 'required|exists:warehouses,id',
                'quantity' => 'required|integer|min:1',
                'unit_price' => 'required|numeric|min:0',
                'discount_percent' => 'nullable|numeric|min:0|max:100',
                'discount_amount' => 'nullable|numeric|min:0',
                'tax_percent' => 'nullable|integer|min:0|max:100',
                'seller_id' => 'nullable|exists:users,id',
                'is_custom_line' => 'nullable|boolean',
                'custom_description' => 'nullable|string|max:255',
            ]);

            $validated = $this->normalizeCustomLineItems([$validated])[0];

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
            $this->normalizeTaxPercentPayload($request);

            $validated = $request->validate([
                'quantity' => 'nullable|integer|min:1',
                'unit_price' => 'nullable|numeric|min:0',
                'discount_percent' => 'nullable|numeric|min:0|max:100',
                'discount_amount' => 'nullable|numeric|min:0',
                'tax_percent' => 'nullable|integer|min:0|max:100',
                'seller_id' => 'nullable|exists:users,id',
                'notes' => 'nullable|string|max:5000',
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
            $this->normalizeTaxPercentPayload($request);

            $validated = $request->validate([
                'items' => 'required|array',
                'items.*.product_id' => 'nullable|exists:products,id',
                'items.*.variant_id' => 'nullable|exists:product_variants,id',
                'items.*.warehouse_id' => 'required|exists:warehouses,id',
                'items.*.qty' => 'required|integer|min:1',
                'items.*.price' => 'required|numeric|min:0',
                'items.*.seller_id' => 'nullable|exists:users,id',
                'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'items.*.tax_percent' => 'nullable|integer|min:0|max:100',
                'items.*.is_custom_line' => 'nullable|boolean',
                'items.*.custom_description' => 'nullable|string|max:255',
                'payment' => 'nullable|array',
                'payment.*.method' => 'required|string',
                'payment.*.amount' => 'required|numeric|min:0',
                'payment.*.notes' => 'nullable|string',
            ]);

            $validated['items'] = $this->normalizeCustomLineItems($validated['items']);

            $order = \App\Models\SalesOrder::findOrFail($id);

            DB::transaction(function () use ($validated, $order) {
                foreach ($validated['items'] as $item) {
                    if (! empty($item['is_custom_line'])) {
                        $subtotal = $item['qty'] * $item['price'];
                        $discount = $subtotal * (($item['discount_percent'] ?? 0) / 100);
                        $tax = ($subtotal - $discount) * (($item['tax_percent'] ?? 0) / 100);

                        $order->items()->create([
                            'product_id' => null,
                            'variant_id' => null,
                            'warehouse_id' => $item['warehouse_id'],
                            'quantity' => $item['qty'],
                            'unit_price' => $item['price'],
                            'unit_cost' => 0,
                            'discount_percent' => $item['discount_percent'] ?? 0,
                            'tax_percent' => $item['tax_percent'] ?? 0,
                            'seller_id' => $item['seller_id'] ?? null,
                            'subtotal' => $subtotal,
                            'discount_amount' => $discount,
                            'tax_amount' => $tax,
                            'total' => $subtotal - $discount + $tax,
                            'is_custom_line' => true,
                            'custom_description' => $item['custom_description'],
                            'added_after_sale' => true,
                        ]);

                        continue;
                    }

                    $query = $order->items()->where('product_id', $item['product_id']);
                    if (! empty($item['variant_id'])) {
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
                            $inventory = \App\Models\ProductInventory::where('warehouse_id', $item['warehouse_id'])
                                ->where('product_id', $item['product_id'])
                                ->when(! empty($item['variant_id']), function ($q) use ($item) {
                                    $q->where('variant_id', $item['variant_id']);
                                }, function ($q) {
                                    $q->whereNull('variant_id');
                                })->first();

                            if ($inventory) {
                                $deduct = min($diffQty, max(0, $inventory->quantity_available));
                                if ($deduct > 0) {
                                    $inventory->decrement('quantity_available', $deduct);
                                }
                            }
                        }
                    } else {
                        // New item
                        $subtotal = $item['qty'] * $item['price'];
                        $discount = $subtotal * (($item['discount_percent'] ?? 0) / 100);
                        $tax = ($subtotal - $discount) * (($item['tax_percent'] ?? 0) / 100);

                        $pricing = \App\Models\ProductPricing::where('product_id', $item['product_id'])
                            ->when(! empty($item['variant_id']), function ($q) use ($item) {
                                $q->where('variant_id', $item['variant_id']);
                            }, function ($q) {
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
                            'added_after_sale' => true,
                        ]);

                        // Deduct available inventory only (never below 0)
                        $inventory = \App\Models\ProductInventory::where('warehouse_id', $item['warehouse_id'])
                            ->where('product_id', $item['product_id'])
                            ->when(! empty($item['variant_id']), function ($q) use ($item) {
                                $q->where('variant_id', $item['variant_id']);
                            }, function ($q) {
                                $q->whereNull('variant_id');
                            })->first();

                        if ($inventory) {
                            $deduct = min($item['qty'], max(0, $inventory->quantity_available));
                            if ($deduct > 0) {
                                $inventory->decrement('quantity_available', $deduct);
                            }
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
                        'total' => $itemSubtotal - $itemDiscount + $itemTax,
                    ]);
                }

                $finalTotal = $orderTotal + $order->shipping_cost - $order->discount_amount;

                /** @var \App\Models\User|null $user */
                $user = auth()->user();

                $logs = $order->audit_logs ?? [];
                $logs[] = [
                    'action' => 'order_modified',
                    'metadata' => [
                        'items_count' => count($validated['items'] ?? []),
                        'total_diff' => $finalTotal - $order->total,
                        'user_id' => $user?->id,
                        'user_name' => $user?->name ?? 'Sistema',
                    ],
                    'timestamp' => now()->toISOString(),
                ];

                $order->update([
                    'total' => $finalTotal,
                    'tax_amount' => $orderTax,
                    'subtotal' => $orderSubtotal,
                    'audit_logs' => $logs,
                ]);

                // 2. Crear cargo en cuenta del cliente por la diferencia
                $diffTotal = $finalTotal - $orderTotal;
                if ($diffTotal > 0 && $order->customer_id) {
                    $customer = \App\Models\Customer::find($order->customer_id);
                    if ($customer) {
                        \App\Models\CustomerTransaction::create([
                            'customer_id' => $customer->id,
                            'type' => 'sale',
                            'amount' => $diffTotal,
                            'balance_before' => $customer->current_balance,
                            'balance_after' => $customer->current_balance + $diffTotal,
                            'description' => 'Cargo por Modificación de Venta '.$order->order_number,
                            'reference_type' => 'sales_order_modification',
                            'reference_id' => $order->id,
                            'transaction_date' => now(),
                            'created_by' => $user?->id,
                        ]);
                        $customer->current_balance += $diffTotal;
                        $customer->save();
                    }
                }

                // 3. Register payments (with isCheckout = false to properly offset the debt charge above)
                if (! empty($validated['payment'])) {
                    foreach ($validated['payment'] as $payment) {
                        $order->addPayment(
                            amount: (float) $payment['amount'],
                            method: $payment['method'],
                            notes: $payment['notes'] ?? 'Cobro por diferencia (Modificación de Orden)',
                            isCheckout: false
                        );
                    }
                    $totalPaid = $order->payments()->sum('amount');
                    if ($totalPaid >= $order->total) {
                        $order->update(['payment_status' => 'paid']);
                    } elseif ($totalPaid > 0) {
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

    private function normalizeTaxPercentPayload(Request $request): void
    {
        $defaultTaxRate = (int) \App\Models\Setting::get('store_tax_rate', 7);

        if ($request->has('tax_percent')) {
            $raw = $request->input('tax_percent');
            if (is_numeric($raw)) {
                $request->merge(['tax_percent' => (int) round((float) $raw)]);
            } elseif ($raw === null || $raw === '') {
                $request->merge(['tax_percent' => $defaultTaxRate]);
            }
        } else {
            $request->merge(['tax_percent' => $defaultTaxRate]);
        }

        $items = $request->input('items');
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            if (! array_key_exists('tax_percent', $item) || $item['tax_percent'] === null || $item['tax_percent'] === '') {
                $items[$index]['tax_percent'] = $defaultTaxRate;
            } elseif (is_numeric($item['tax_percent'])) {
                $items[$index]['tax_percent'] = (int) round((float) $item['tax_percent']);
            }
        }

        $request->merge(['items' => $items]);
    }

    /**
     * Validates and normalizes mixed catalog/custom lines.
     *
     * @throws ValidationException
     */
    private function normalizeCustomLineItems(array $items): array
    {
        $errors = [];

        foreach ($items as $index => $item) {
            $isCustom = (bool) ($item['is_custom_line'] ?? false);

            if ($isCustom) {
                $description = trim((string) ($item['custom_description'] ?? ''));
                if ($description === '') {
                    $errors["items.{$index}.custom_description"] = 'La descripcion es obligatoria para articulos miscelaneos.';

                    continue;
                }

                $items[$index]['is_custom_line'] = true;
                $items[$index]['custom_description'] = $description;
                $items[$index]['product_id'] = null;
                $items[$index]['variant_id'] = null;

                continue;
            }

            if (empty($item['product_id'])) {
                $errors["items.{$index}.product_id"] = 'El producto es obligatorio para lineas de catalogo.';

                continue;
            }

            $items[$index]['is_custom_line'] = false;
            $items[$index]['custom_description'] = null;
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $items;
    }

    /**
     * GET /api/sales-orders/pending-at-warehouse
     */
    public function pendingAtWarehouse(Request $request): JsonResponse
    {
        $warehouseId = $request->header('X-Warehouse-Id')
            ?? $request->user()?->current_warehouse_id;

        $orders = \App\Models\SalesOrder::with(['customer', 'user', 'items.product'])
            ->when($warehouseId, fn ($q) => $q->whereHas('items', function ($iq) use ($warehouseId) {
                $iq->where('warehouse_id', $warehouseId);
            }))
            ->whereNotNull('sent_to_warehouse_at')
            ->whereNull('prepared_at')
            ->orderBy('sent_to_warehouse_at', 'asc')
            ->get();

        return response()->json([
            'data' => $orders,
            'count' => $orders->count(),
        ]);
    }

    /**
     * GET /api/sales-orders/pending-delivery
     */
    public function pendingDelivery(Request $request): JsonResponse
    {
        $warehouseId = $request->header('X-Warehouse-Id')
            ?? $request->user()?->current_warehouse_id;

        $orders = \App\Models\SalesOrder::with(['customer', 'user', 'items.product'])
            ->when($warehouseId, fn ($q) => $q->whereHas('items', function ($iq) use ($warehouseId) {
                $iq->where('warehouse_id', $warehouseId);
            }))
            ->whereNotNull('prepared_at')
            ->where('status', 'confirmed')
            ->orderBy('prepared_at', 'asc')
            ->get();

        return response()->json([
            'data' => $orders,
            'count' => $orders->count(),
        ]);
    }

    /**
     * POST /api/sales-orders/{id}/send-to-warehouse
     */
    public function sendToWarehouse(int $id): JsonResponse
    {
        $order = \App\Models\SalesOrder::findOrFail($id);
        if (! $order->sendToWarehouse()) {
            return response()->json(['message' => 'La orden ya está en cola de bodega'], 422);
        }

        return response()->json(['message' => 'Enviada a bodega', 'data' => $order->fresh()]);
    }

    /**
     * POST /api/sales-orders/{id}/mark-prepared
     */
    public function markPrepared(Request $request, int $id): JsonResponse
    {
        $order = \App\Models\SalesOrder::findOrFail($id);
        if (! $order->markAsPrepared($request->user()->id)) {
            return response()->json(['message' => 'La orden ya fue preparada'], 422);
        }

        return response()->json(['message' => 'Marcada como preparada', 'data' => $order->fresh()]);
    }
}
