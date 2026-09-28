<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\IdempotencyConflictException;
use App\Helpers\TaxRateHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\PosCheckoutRequest;
use App\Models\CustomerVehicle;
use App\Models\Inventory;
use App\Models\PaymentMethod;
use App\Models\SalesOrder;
use App\Services\SalesOrderCancellationService;
use App\Services\SalesOrderOperationalDetailService;
use App\Services\SalesOrderService;
use App\Services\TransactionTraceService;
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
     * GET /api/sales-orders/{salesOrder}/operational-detail
     * Get the complete operational profile for an invoice/order.
     */
    public function operationalDetail(Request $request, SalesOrder $salesOrder, SalesOrderOperationalDetailService $operationalDetailService, TransactionTraceService $traceService): JsonResponse
    {
        $data = $operationalDetailService->get($salesOrder);
        $data['trace'] = $traceService->get('sales_order', $salesOrder->id, $request->user(), 1, 50);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
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

            $validPaymentMethods = PaymentMethod::validCodesForContext(
                PaymentMethod::CONTEXT_SALES,
                false,
                ['supplier_credit'],
            );

            if ($validPaymentMethods === []) {
                $validPaymentMethods = ['cash', 'card', 'transfer', 'credit', 'store_credit'];
            }

            $validated = $request->validate([
                'idempotency_key' => [
                    Rule::requiredIf(fn (): bool => $request->filled('source_quote_id')),
                    'nullable',
                    'uuid',
                ],
                'user_id' => 'nullable|integer|min:1',
                'customer_id' => 'nullable|exists:customers,id',
                'customer_vehicle_id' => 'nullable|exists:customer_vehicles,id',
                'referred_by_customer_id' => ['nullable', Rule::exists('customers', 'id')->where('status', 'active')],
                'date' => 'nullable|date',
                'status' => 'nullable|in:draft,pending,confirmed',
                'source_quote_id' => 'nullable|exists:sales_quotes,id',
                'discount_amount' => 'nullable|numeric|min:0',
                'shipping_cost' => 'nullable|numeric|min:0',
                'payment_method' => ['nullable', Rule::in(array_values(array_unique(array_merge($validPaymentMethods, ['multiple']))))],
                'payment_status' => 'nullable|in:pending,partial,paid,refunded',
                'initial_payment' => 'nullable|numeric|min:0',
                'notes' => 'nullable|string|max:5000',
                'internal_notes' => 'nullable|string|max:5000',
                'audit_logs' => 'nullable|array',
                'payments' => 'nullable|array',
                'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
                'payments.*.method' => ['required_with:payments', Rule::in($validPaymentMethods)],
                'payments.*.reference_number' => 'nullable|string|max:100',
                'payments.*.notes' => 'nullable|string',
                'payments.*.financial_account_id' => 'nullable|integer|exists:financial_accounts,id',
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
                'items.*.source_quote_item_id' => 'nullable|integer|exists:sales_quote_items,id',
            ]);

            if (! empty($validated['items'])) {
                $validated['items'] = $this->normalizeCustomLineItems($validated['items']);
            }

            $this->validateCustomerVehicle($validated);

            // Preserve specific payment methods. Only translate legacy aliases
            // that are not real configured method codes.
            $paymentTypeMap = [
                'clave' => 'card',
                'transferencia' => 'transfer',
                'notaCredito' => 'credit',
                'creditoCliente' => 'credit',
            ];

            if (! empty($validated['payments'])) {
                foreach ($validated['payments'] as &$payment) {
                    $originalMethod = $payment['method'];
                    if (
                        isset($paymentTypeMap[$originalMethod])
                        && ! in_array($originalMethod, $validPaymentMethods, true)
                    ) {
                        $payment['method'] = $paymentTypeMap[$originalMethod];
                    }
                }
                unset($payment); // break reference
            }

            // ── Defensive stock check — collect warnings only, do NOT block ──
            $stockWarnings = [];
            $isCashierQuoteCheckout = ! empty($validated['source_quote_id']);
            if (! $isCashierQuoteCheckout && ! empty($validated['items'])) {
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

                    $systemPrice = $this->systemSalePriceForItem($item, $validated['warehouse_id'] ?? null);
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

            $checkoutCustomerId = $validated['customer_id'] ?? null;
            if (! $checkoutCustomerId && ! empty($validated['source_quote_id'])) {
                $checkoutCustomerId = \App\Models\SalesQuote::query()
                    ->whereKey($validated['source_quote_id'])
                    ->value('customer_id');
            }

            if ($hasCredit && ! $checkoutCustomerId) {
                return response()->json([
                    'message' => 'Selecciona un cliente para vender a credito.',
                ], 422);
            }

            if (
                $hasCredit
                && $checkoutCustomerId
            ) {
                $customer = \App\Models\Customer::find($checkoutCustomerId);

                if ($customer) {
                    $creditAvailable = max(
                        0,
                        (float) $customer->credit_limit - (float) $customer->current_balance,
                    );

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
        } catch (IdempotencyConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
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

            $validPaymentMethods = PaymentMethod::validCodesForContext(
                PaymentMethod::CONTEXT_SALES,
                false,
                ['supplier_credit'],
            );

            if ($validPaymentMethods === []) {
                $validPaymentMethods = ['cash', 'card', 'transfer', 'credit', 'store_credit'];
            }

            $validated = $request->validate([
                'customer_id' => 'nullable|exists:customers,id',
                'customer_vehicle_id' => 'nullable|exists:customer_vehicles,id',
                'referred_by_customer_id' => ['nullable', Rule::exists('customers', 'id')->where('status', 'active')],
                'date' => 'nullable|date',
                'discount_amount' => 'nullable|numeric|min:0',
                'shipping_cost' => 'nullable|numeric|min:0',
                'payment_method' => ['nullable', Rule::in(array_values(array_unique(array_merge($validPaymentMethods, ['multiple']))))],
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

            $this->validateCustomerVehicle($validated);

            // ── Enforce price override permission (R05) ──
            $userRole = $request->user()->role ?? '';
            $canOverridePrice = in_array($userRole, ['admin', 'manager']);

            if (! $canOverridePrice && ! empty($validated['items'])) {
                foreach ($validated['items'] as &$item) {
                    if (! empty($item['is_custom_line'])) {
                        continue;
                    }

                    $systemPrice = $this->systemSalePriceForItem($item, $validated['warehouse_id'] ?? null);
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
    public function cancel(Request $request, int $id, SalesOrderCancellationService $cancellations): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reason' => ['required', 'string', 'min:3', 'max:1000'],
                'refund_method' => ['nullable', 'string', 'max:80'],
                'financial_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);

            $order = $cancellations->cancel(
                SalesOrder::query()->findOrFail($id),
                $validated,
                $request->user(),
            );

            return response()->json([
                'message' => $order->cancellation_return_id
                    ? 'Factura anulada. La resolucion financiera fue enviada a Caja.'
                    : 'Orden anulada exitosamente.',
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
    public function addItemsToExistingOrder(\App\Http\Requests\AmendSalesOrderRequest $request, int $id, \App\Services\SalesOrderAmendmentService $service): JsonResponse
    {
        try {
            $result = $service->amend($id, $request->validated(), $request->user());
        } catch (IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'success' => true,
            'message' => $result['replayed'] ? 'Ampliación recuperada.' : 'Transacción ampliada.',
            'data' => $result['order'],
            'difference' => $result['difference'],
            'replayed' => $result['replayed'],
        ]);
    }

    private function normalizeTaxPercentPayload(Request $request): void
    {
        $defaultTaxRate = TaxRateHelper::defaultRate();

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
     * @throws ValidationException
     */
    private function validateCustomerVehicle(array $data): void
    {
        if (empty($data['customer_vehicle_id'])) {
            return;
        }

        if (empty($data['customer_id'])) {
            throw ValidationException::withMessages([
                'customer_vehicle_id' => 'Selecciona un cliente antes de seleccionar un carro.',
            ]);
        }

        $vehicleBelongsToCustomer = CustomerVehicle::query()
            ->whereKey($data['customer_vehicle_id'])
            ->where('customer_id', $data['customer_id'])
            ->where('status', 'active')
            ->exists();

        if (! $vehicleBelongsToCustomer) {
            throw ValidationException::withMessages([
                'customer_vehicle_id' => 'El carro seleccionado no pertenece al cliente de la venta.',
            ]);
        }
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

        $orders = \App\Models\SalesOrder::with(['customer', 'user', 'items.product', 'items.variant.brand'])
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

    private function systemSalePriceForItem(array $item, ?int $orderWarehouseId = null): ?float
    {
        $productId = (int) ($item['product_id'] ?? 0);
        $variantId = ! empty($item['variant_id']) ? (int) $item['variant_id'] : null;
        $warehouseId = ! empty($item['warehouse_id']) ? (int) $item['warehouse_id'] : $orderWarehouseId;

        if ($productId > 0 && $warehouseId) {
            $fifoPrice = app(\App\Services\InventoryFifoService::class)
                ->currentFifoPricing($productId, $variantId, (int) $warehouseId);

            if (($fifoPrice['active_lot_id'] ?? null) !== null) {
                return (float) $fifoPrice['current_sale_price'];
            }
        }

        $pricingQuery = \App\Models\ProductPricing::query();
        if ($variantId) {
            $pricingQuery->where('variant_id', $variantId);
        } else {
            $pricingQuery->where('product_id', $productId)->whereNull('variant_id');
        }

        $price = $pricingQuery->value('base_price');

        return $price !== null ? (float) $price : null;
    }
}
