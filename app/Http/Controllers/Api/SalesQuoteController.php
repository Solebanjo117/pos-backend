<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesQuote;
use App\Models\SalesQuoteItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\ProductInventory;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesQuoteController extends Controller
{
    /**
     * GET /api/sales-quotes
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = SalesQuote::with(['customer', 'user', 'warehouse', 'items.product'])
                ->orderByDesc('created_at');

            if ($request->filled('status')) {
                $query->byStatus($request->status);
            }
            if ($request->filled('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('quote_number', 'ilike', "%{$search}%")
                      ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$search}%"));
                });
            }
            if ($request->filled('date_from')) {
                $query->where('date', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->where('date', '<=', $request->date_to);
            }

            $perPage = $request->input('per_page', 20);
            $quotes = $query->paginate($perPage);

            return response()->json($quotes);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener cotizaciones', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/sales-quotes/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $quote = SalesQuote::with(['customer', 'user', 'warehouse', 'items.product', 'items.variant', 'convertedOrder'])
                ->findOrFail($id);

            return response()->json($quote);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Cotización no encontrada', 'error' => $e->getMessage()], 404);
        }
    }

    /**
     * POST /api/sales-quotes
     */
    public function store(Request $request): JsonResponse
    {
        $this->normalizeTaxPercentPayload($request);

        $validated = $request->validate([
            'customer_id'              => 'nullable|exists:customers,id',
            'warehouse_id'             => 'nullable|exists:warehouses,id',
            'date'                     => 'nullable|date',
            'valid_until'              => 'nullable|date|after_or_equal:date',
            'notes'                    => 'nullable|string',
            'internal_notes'           => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'nullable|exists:products,id',
            'items.*.variant_id'       => 'nullable|exists:product_variants,id',
            'items.*.warehouse_id'     => 'required|exists:warehouses,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.unit_price'       => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_percent'      => 'nullable|integer|min:0|max:100',
            'items.*.is_custom_line'   => 'nullable|boolean',
            'items.*.custom_description' => 'nullable|string|max:255',
        ]);

        $validated['items'] = $this->normalizeCustomLineItems($validated['items']);

        // ── Enforce price override permission (R05) ──
        $userRole = $request->user()->role ?? '';
        $canOverridePrice = in_array($userRole, ['admin', 'manager']);

        if (!$canOverridePrice && !empty($validated['items'])) {
            foreach ($validated['items'] as &$item) {
                if (!empty($item['is_custom_line'])) {
                    continue;
                }

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

        $this->validateStockForQuoteItems($validated['items'], $validated['warehouse_id'] ?? null);

        try {
            $quote = DB::transaction(function () use ($validated, $request) {
                $quote = SalesQuote::create([
                    'customer_id'    => $validated['customer_id'] ?? null,
                    'user_id'        => $request->user()->id,
                    'warehouse_id'   => $validated['warehouse_id'] ?? $request->user()->current_warehouse_id,
                    'date'           => $validated['date'] ?? now(),
                    'valid_until'    => $validated['valid_until'] ?? now()->addDays(15),
                    'status'         => 'draft',
                    'notes'          => $validated['notes'] ?? null,
                    'internal_notes' => $validated['internal_notes'] ?? null,
                ]);

                foreach ($validated['items'] as $itemData) {
                    $quote->items()->create([
                        'product_id'       => $itemData['product_id'] ?? null,
                        'variant_id'       => $itemData['variant_id'] ?? null,
                        'warehouse_id'     => $itemData['warehouse_id'],
                        'quantity'         => $itemData['quantity'],
                        'unit_price'       => $itemData['unit_price'],
                        'discount_percent' => $itemData['discount_percent'] ?? 0,
                        'tax_percent'      => $itemData['tax_percent'] ?? 0,
                        'is_custom_line'   => (bool) ($itemData['is_custom_line'] ?? false),
                        'custom_description' => $itemData['custom_description'] ?? null,
                    ]);
                }

                $quote->calculateTotals();

                return $quote->load(['customer', 'user', 'items.product']);
            });

            return response()->json([
                'message' => 'Cotización creada exitosamente',
                'data'    => $quote,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al crear cotización', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/sales-quotes/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $quote = SalesQuote::findOrFail($id);

        if (! in_array($quote->status, ['draft', 'sent'])) {
            return response()->json(['message' => 'Solo se pueden editar cotizaciones en borrador o enviadas.'], 422);
        }

        $this->normalizeTaxPercentPayload($request);

        $validated = $request->validate([
            'customer_id'              => 'nullable|exists:customers,id',
            'warehouse_id'             => 'nullable|exists:warehouses,id',
            'valid_until'              => 'nullable|date',
            'notes'                    => 'nullable|string',
            'internal_notes'           => 'nullable|string',
            'status'                   => 'nullable|in:draft,sent,accepted,rejected',
            'items'                    => 'nullable|array|min:1',
            'items.*.product_id'       => 'nullable|exists:products,id',
            'items.*.variant_id'       => 'nullable|exists:product_variants,id',
            'items.*.warehouse_id'     => 'required|exists:warehouses,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.unit_price'       => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_percent'      => 'nullable|integer|min:0|max:100',
            'items.*.is_custom_line'   => 'nullable|boolean',
            'items.*.custom_description' => 'nullable|string|max:255',
        ]);

        if (!empty($validated['items'])) {
            $validated['items'] = $this->normalizeCustomLineItems($validated['items']);
        }

        // ── Enforce price override permission (R05) ──
        $userRole = $request->user()->role ?? '';
        $canOverridePrice = in_array($userRole, ['admin', 'manager']);

        if (!$canOverridePrice && !empty($validated['items'])) {
            foreach ($validated['items'] as &$item) {
                if (!empty($item['is_custom_line'])) {
                    continue;
                }

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

        if (!empty($validated['items'])) {
            $this->validateStockForQuoteItems(
                $validated['items'],
                $validated['warehouse_id'] ?? $quote->warehouse_id
            );
        }

        try {
            DB::transaction(function () use ($quote, $validated) {
                $quote->update(collect($validated)->only(['customer_id', 'warehouse_id', 'valid_until', 'notes', 'internal_notes', 'status'])->toArray());

                if (isset($validated['items'])) {
                    $quote->items()->delete();

                    foreach ($validated['items'] as $itemData) {
                        $quote->items()->create([
                            'product_id'       => $itemData['product_id'] ?? null,
                            'variant_id'       => $itemData['variant_id'] ?? null,
                            'warehouse_id'     => $itemData['warehouse_id'],
                            'quantity'         => $itemData['quantity'],
                            'unit_price'       => $itemData['unit_price'],
                            'discount_percent' => $itemData['discount_percent'] ?? 0,
                            'tax_percent'      => $itemData['tax_percent'] ?? 0,
                            'is_custom_line'   => (bool) ($itemData['is_custom_line'] ?? false),
                            'custom_description' => $itemData['custom_description'] ?? null,
                        ]);
                    }

                    $quote->calculateTotals();
                }
            });

            return response()->json([
                'message' => 'Cotización actualizada',
                'data'    => $quote->fresh(['customer', 'user', 'items.product']),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al actualizar', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/sales-quotes/from-pos
     * Create a draft quote directly from a POS cart payload.
     */
    public function storeFromPos(Request $request): JsonResponse
    {
        $this->normalizeTaxPercentPayload($request);

        $validated = $request->validate([
            'customer_id'              => 'nullable|exists:customers,id',
            'warehouse_id'             => 'nullable|exists:warehouses,id',
            'notes'                    => 'nullable|string',
            'internal_notes'           => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'nullable|exists:products,id',
            'items.*.variant_id'       => 'nullable|exists:product_variants,id',
            'items.*.warehouse_id'     => 'required|exists:warehouses,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.unit_price'       => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_percent'      => 'nullable|integer|min:0|max:100',
            'items.*.is_custom_line'   => 'nullable|boolean',
            'items.*.custom_description' => 'nullable|string|max:255',
        ]);

        $validated['items'] = $this->normalizeCustomLineItems($validated['items']);

        // ── Enforce price override permission (R05) ──
        $userRole = $request->user()->role ?? '';
        $canOverridePrice = in_array($userRole, ['admin', 'manager']);

        if (!$canOverridePrice && !empty($validated['items'])) {
            foreach ($validated['items'] as &$item) {
                if (!empty($item['is_custom_line'])) {
                    continue;
                }

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

        $this->validateStockForQuoteItems($validated['items'], $validated['warehouse_id'] ?? null);

        try {
            $quote = DB::transaction(function () use ($validated, $request) {
                $quote = SalesQuote::create([
                    'customer_id'    => $validated['customer_id'] ?? null,
                    'user_id'        => $request->user()->id,
                    'warehouse_id'   => $validated['warehouse_id'] ?? $request->user()->current_warehouse_id,
                    'date'           => now(),
                    'valid_until'    => null, // No expiry by default (configurable from admin)
                    'status'         => 'draft',
                    'notes'          => $validated['notes'] ?? null,
                    'internal_notes' => $validated['internal_notes'] ?? null,
                ]);

                foreach ($validated['items'] as $itemData) {
                    $quote->items()->create([
                        'product_id'       => $itemData['product_id'] ?? null,
                        'variant_id'       => $itemData['variant_id'] ?? null,
                        'warehouse_id'     => $itemData['warehouse_id'],
                        'quantity'         => $itemData['quantity'],
                        'unit_price'       => $itemData['unit_price'],
                        'discount_percent' => $itemData['discount_percent'] ?? 0,
                        'tax_percent'      => $itemData['tax_percent'] ?? 0,
                        'is_custom_line'   => (bool) ($itemData['is_custom_line'] ?? false),
                        'custom_description' => $itemData['custom_description'] ?? null,
                    ]);
                }

                $quote->calculateTotals();

                return $quote->load(['customer', 'user', 'items.product']);
            });

            return response()->json([
                'message' => 'Cotización guardada exitosamente',
                'data'    => $quote,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al guardar cotización', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/sales-quotes/{id}/send-to-deposit
     * Convert quote to order and send to warehouse queue
     */
    public function sendToDeposit(Request $request, int $id): JsonResponse
    {
        $quote = SalesQuote::with('items')->findOrFail($id);

        if (!in_array($quote->status, ['draft', 'sent'])) {
            return response()->json([
                'message' => "No se puede enviar a depósito desde el estado '{$quote->status_label}'.",
            ], 422);
        }

        if (!$quote->canBeConverted()) {
            return response()->json([
                'message' => 'Esta cotización no puede ser convertida. Verifica que tenga artículos.',
            ], 422);
        }

        try {
            $order = DB::transaction(function () use ($quote, $request) {
                // Create the SalesOrder from the quote
                $order = SalesOrder::create([
                    'customer_id'     => $quote->customer_id,
                    'user_id'         => $request->user()->id,
                    'date'            => now(),
                    'status'          => 'pending',
                    'payment_method'  => 'pending',
                    'payment_status'  => 'pending',
                    'notes'           => "Convertida de cotización {$quote->quote_number} y enviada a bodega",
                ]);

                // Copy items
                foreach ($quote->items as $qi) {
                    SalesOrderItem::create([
                        'sales_order_id'   => $order->id,
                        'product_id'       => $qi->product_id,
                        'variant_id'       => $qi->is_custom_line ? null : $qi->variant_id,
                        'warehouse_id'     => $qi->warehouse_id,
                        'quantity'         => $qi->quantity,
                        'unit_price'       => $qi->unit_price,
                        'discount_percent' => $qi->discount_percent,
                        'discount_amount'  => $qi->discount_amount,
                        'tax_percent'      => $qi->tax_percent,
                        'tax_amount'       => $qi->tax_amount,
                        'subtotal'         => $qi->subtotal,
                        'total'            => $qi->total,
                        'is_custom_line'   => (bool) $qi->is_custom_line,
                        'custom_description' => $qi->custom_description,
                    ]);
                }

                $order->calculateTotals();

                // Send to warehouse queue
                $order->update(['sent_to_warehouse_at' => now()]);

                // Mark quote as converted
                $quote->update([
                    'status'             => 'converted',
                    'converted_order_id' => $order->id,
                ]);

                return $order->fresh(['customer', 'user', 'items.product']);
            });

            return response()->json([
                'message' => "Cotización {$quote->quote_number} convertida a Orden {$order->order_number} y enviada a bodega",
                'data'    => $order,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al convertir y enviar a bodega: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/sales-quotes/{id}/send-to-cashier
     */
    public function sendToCashier(int $id): JsonResponse
    {
        $quote = SalesQuote::findOrFail($id);

        if (!$quote->sendToCashier()) {
            return response()->json([
                'message' => "No se puede enviar a caja desde el estado '{$quote->status_label}'.",
            ], 422);
        }

        return response()->json([
            'message' => "Cotización {$quote->quote_number} enviada a Caja",
            'data'    => $quote->fresh(),
        ]);
    }

    /**
     * DELETE /api/v1/sales-quotes/{id}/cashier-queue
     * Remove quote from cashier queue (does not delete the quote)
     *
     * Estados de SalesQuote: draft, sent, sent_to_deposit, sent_to_cashier,
     * accepted, collected, rejected, expired, converted
     *
     * Flujo normal: draft/sent/sent_to_deposit → sendToCashier() → sent_to_cashier
     * Al remover de cola: sent_to_cashier → sent (estado genérico de "pendiente")
     */
    public function removeFromCashierQueue(int $id): JsonResponse
    {
        $quote = SalesQuote::findOrFail($id);

        if ($quote->status !== 'sent_to_cashier') {
            return response()->json([
                'message' => 'Esta cotización no está en la cola de caja.',
            ], 422);
        }

        // Revertir a estado 'sent' (genérico de cotización pendiente)
        // NO usar 'draft' porque ya fue procesada/enviada
        $quote->update([
            'status' => 'sent',
            'sent_to_cashier_at' => null,
        ]);

        return response()->json([
            'message' => "Cotización {$quote->quote_number} removida de la cola de caja",
            'data' => $quote->fresh(),
        ]);
    }

    /**
     * POST /api/sales-quotes/{id}/mark-collected
     */
    public function markCollected(int $id): JsonResponse
    {
        $quote = SalesQuote::findOrFail($id);

        if (!$quote->markCollected()) {
            return response()->json([
                'message' => "No se puede marcar como cobrada desde el estado '{$quote->status_label}'.",
            ], 422);
        }

        return response()->json([
            'message' => "Cotización {$quote->quote_number} marcada como Cobrada",
            'data'    => $quote->fresh(),
        ]);
    }

    /**
     * GET /api/sales-quotes/pending-at-cashier
     */
    public function pendingAtCashier(Request $request): JsonResponse
    {
        $warehouseId = $request->header('X-Warehouse-Id')
            ?? $request->user()?->current_warehouse_id;

        $quotes = SalesQuote::with(['customer', 'user', 'items.product'])
            ->when($warehouseId, fn($q) => $q->where('warehouse_id', $warehouseId))
            ->where('status', 'sent_to_cashier')
            ->orderBy('sent_to_cashier_at', 'asc')
            ->get();

        return response()->json([
            'data'  => $quotes,
            'count' => $quotes->count(),
        ]);
    }

    /**
     * DELETE /api/sales-quotes/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $quote = SalesQuote::findOrFail($id);

        if ($quote->status === 'converted') {
            return response()->json(['message' => 'No se puede eliminar una cotización ya convertida.'], 422);
        }

        $quote->delete();

        return response()->json(['message' => 'Cotización eliminada']);
    }

    /**
     * POST /api/sales-quotes/{id}/convert
     * Convert quote to a SalesOrder
     */
    public function convert(Request $request, int $id): JsonResponse
    {
        $quote = SalesQuote::with('items')->findOrFail($id);

        if (! $quote->canBeConverted()) {
            return response()->json([
                'message' => 'Esta cotización no puede ser convertida. Verifica su estado y que tenga artículos.',
            ], 422);
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:cash,card,transfer,credit,multiple',
            'payment_status' => 'nullable|in:pending,partial,paid',
            'initial_payment' => 'nullable|numeric|min:0',
        ]);

        try {
            $order = DB::transaction(function () use ($quote, $validated, $request) {
                // Create the SalesOrder from the quote
                $order = SalesOrder::create([
                    'customer_id'     => $quote->customer_id,
                    'user_id'         => $request->user()->id,
                    'date'            => now(),
                    'status'          => 'draft',
                    'payment_method'  => $validated['payment_method'],
                    'payment_status'  => $validated['payment_status'] ?? 'pending',
                    'notes'           => "Convertida de cotización {$quote->quote_number}",
                ]);

                // Copy items
                foreach ($quote->items as $qi) {
                    SalesOrderItem::create([
                        'sales_order_id'   => $order->id,
                        'product_id'       => $qi->product_id,
                        'variant_id'       => $qi->is_custom_line ? null : $qi->variant_id,
                        'warehouse_id'     => $qi->warehouse_id,
                        'quantity'         => $qi->quantity,
                        'unit_price'       => $qi->unit_price,
                        'discount_percent' => $qi->discount_percent,
                        'discount_amount'  => $qi->discount_amount,
                        'tax_percent'      => $qi->tax_percent,
                        'tax_amount'       => $qi->tax_amount,
                        'subtotal'         => $qi->subtotal,
                        'total'            => $qi->total,
                        'is_custom_line'   => (bool) $qi->is_custom_line,
                        'custom_description' => $qi->custom_description,
                    ]);
                }

                $order->calculateTotals();

                // Confirm the order (deducts inventory)
                $order->confirm();

                // Handle initial payment if any
                if (! empty($validated['initial_payment']) && $validated['initial_payment'] > 0) {
                    $order->addPayment(
                        $validated['initial_payment'],
                        $validated['payment_method'],
                        "Pago al convertir cotización {$quote->quote_number}"
                    );
                }

                // Mark quote as converted
                $quote->update([
                    'status'             => 'converted',
                    'converted_order_id' => $order->id,
                ]);

                return $order->fresh(['customer', 'user', 'items.product']);
            });

            return response()->json([
                'message' => "Cotización {$quote->quote_number} convertida a Orden {$order->order_number}",
                'data'    => $order,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al convertir cotización',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate stock availability for quote items when the setting is enabled.
     *
     * @param  array       $items       Quote items with product_id, variant_id, warehouse_id, quantity
     * @param  int|null    $warehouseId Header-level warehouse (fallback when item has none)
     * @throws \Exception  When stock is insufficient
     */
    private function validateStockForQuoteItems(array $items, ?int $warehouseId): void
    {
        if (!Setting::get('require_stock_for_quotes', false)) {
            return; // Setting disabled — skip check
        }

        $insufficient = [];

        foreach ($items as $item) {
            if (!empty($item['is_custom_line'])) {
                continue;
            }

            $qty            = (int) ($item['quantity'] ?? 1);
            $productId      = $item['product_id'];
            $variantId      = $item['variant_id'] ?? null;
            $itemWarehouse  = $item['warehouse_id'] ?? $warehouseId;

            if (!$itemWarehouse) {
                continue; // Can't check without warehouse context
            }

            $query = ProductInventory::where('product_id', $productId)
                ->where('warehouse_id', $itemWarehouse);

            if ($variantId) {
                $query->where('variant_id', $variantId);
            } else {
                $query->whereNull('variant_id');
            }

            $inventory = $query->first();
            $available = $inventory?->quantity_available ?? 0;

            if ($available < $qty) {
                $insufficient[] = "Producto #{$productId}" .
                    ($variantId ? " (variante #{$variantId})" : '') .
                    ": disponible {$available}, solicitado {$qty}";
            }
        }

        if (!empty($insufficient)) {
            throw new \Exception('Stock insuficiente para la cotización: ' . implode('; ', $insufficient));
        }
    }

    private function normalizeTaxPercentPayload(Request $request): void
    {
        if ($request->has('tax_percent') && is_numeric($request->input('tax_percent'))) {
            $request->merge([
                'tax_percent' => (int) round((float) $request->input('tax_percent')),
            ]);
        }

        $items = $request->input('items');
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (!is_array($item) || !array_key_exists('tax_percent', $item) || !is_numeric($item['tax_percent'])) {
                continue;
            }

            $items[$index]['tax_percent'] = (int) round((float) $item['tax_percent']);
        }

        $request->merge(['items' => $items]);
    }

    /**
     * Validates and normalizes mixed catalog/custom quote lines.
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

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $items;
    }

    /**
     * Generate a PNG barcode as a base64 data URI from the quote number.
     */
    private function generateBarcodeSvg(string $quoteNumber): string
    {
        $numericValue = preg_replace('/\D/', '', $quoteNumber);
        if (empty($numericValue)) {
            $numericValue = $quoteNumber;
        }

        $generator = new \Picqer\Barcode\BarcodeGeneratorPNG();
        $png = $generator->getBarcode($numericValue, $generator::TYPE_CODE_128, 2, 50);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    /**
     * GET /api/sales-quotes/{id}/ticket
     * Generate a thermal ticket PDF for a sales quote.
     */
    public function ticket(Request $request, int $id)
    {
        $type = $request->query('mode', 'standard'); // standard or blind (sin SKU)
        
        $quote = SalesQuote::with([
            'items.product',
            'items.variant',
            'items.warehouse',
            'customer',
            'user',
        ])->findOrFail($id);

        // Business info from configurable settings
        $businessName    = \App\Models\Setting::get('store_name', 'Mi Negocio');
        $businessAddress = \App\Models\Setting::get('store_address', '');
        $businessPhone   = \App\Models\Setting::get('store_phone', '');
        $businessRfc     = \App\Models\Setting::get('store_tax_id', '');
        $businessEmail   = \App\Models\Setting::get('store_email', '');
        $businessLogo    = \App\Models\Setting::get('store_logo_url', null);
        $ticketFooter    = \App\Models\Setting::get('store_ticket_footer', 'Cotización válida hasta: ' . ($quote->valid_until ? date('d/m/Y', strtotime($quote->valid_until)) : 'N/A'));

        // Generate barcode SVG
        $barcodeSvg   = $this->generateBarcodeSvg($quote->quote_number);
        $barcodeValue = preg_replace('/\D/', '', $quote->quote_number);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('tickets.sale', [
            'order'           => $quote, // using generic 'order' var the template expects
            'is_quote'        => true,
            'businessName'    => $businessName,
            'businessAddress' => $businessAddress,
            'businessPhone'   => $businessPhone,
            'businessEmail'   => $businessEmail,
            'businessRfc'     => $businessRfc,
            'businessLogo'    => $businessLogo,
            'ticketFooter'    => $ticketFooter,
            'type'            => $type === 'blind' ? 'client' : 'internal', // Map blind to client (no SKU)
            'barcodeSvg'      => $barcodeSvg,
            'barcodeValue'    => $barcodeValue,
        ]);

        // 80mm ≈ 226.77pt, height auto (long receipt)
        $pdf->setPaper([0, 0, 226.77, 800], 'portrait');
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('defaultFont', 'Courier');

        return $pdf->stream("cotizacion-ticket-{$quote->quote_number}.pdf");
    }

    /**
     * GET /api/sales-quotes/{id}/proforma
     * Generate a half-letter landscape proforma for a sales quote.
     */
    public function proforma(Request $request, int $id)
    {
        $type = $request->query('mode', 'standard');

        $quote = SalesQuote::with([
            'items.product.category',
            'items.product.variants.brand',
            'items.product.variants.pricing',
            'items.product.vins',
            'items.variant',
            'items.warehouse',
            'customer',
            'user',
        ])->findOrFail($id);

        $businessName    = \App\Models\Setting::get('store_name', 'Mi Negocio');
        $businessAddress = \App\Models\Setting::get('store_address', '');
        $businessPhone   = \App\Models\Setting::get('store_phone', '');
        $businessEmail   = \App\Models\Setting::get('store_email', '');
        $businessRfc     = \App\Models\Setting::get('store_tax_id', '');
        $businessLogo    = \App\Models\Setting::get('store_logo_url', null);
        $ticketFooter    = \App\Models\Setting::get('store_ticket_footer', 'Cotización válida por 15 días');

        // Calculate dominant tax percent
        $taxPercent = 7;
        if ($quote->items->count() > 0) {
            $taxPercent = $quote->items->first()->tax_percent ?? 7;
        }

        $barcodeSvg   = $this->generateBarcodeSvg($quote->quote_number);
        $barcodeValue = preg_replace('/\D/', '', $quote->quote_number);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('tickets.proforma', [
            'order'           => $quote, // mapped for template expectations
            'is_quote'        => true,
            'businessName'    => $businessName,
            'businessAddress' => $businessAddress,
            'businessPhone'   => $businessPhone,
            'businessEmail'   => $businessEmail,
            'businessRfc'     => $businessRfc,
            'businessLogo'    => $businessLogo,
            'ticketFooter'    => $ticketFooter,
            'type'            => $type === 'blind' ? 'client' : 'internal',
            'taxPercent'      => $taxPercent,
            'barcodeSvg'      => $barcodeSvg,
            'barcodeValue'    => $barcodeValue,
        ]);

        $pdf->setPaper([0, 0, 612, 396], 'landscape');
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('defaultFont', 'Helvetica');

        return $pdf->stream("cotizacion-proforma-{$quote->quote_number}.pdf");
    }
}
