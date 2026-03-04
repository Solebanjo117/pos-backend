<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesQuote;
use App\Models\SalesQuoteItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
        $validated = $request->validate([
            'customer_id'              => 'nullable|exists:customers,id',
            'warehouse_id'             => 'nullable|exists:warehouses,id',
            'date'                     => 'nullable|date',
            'valid_until'              => 'nullable|date|after_or_equal:date',
            'notes'                    => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'required|exists:products,id',
            'items.*.variant_id'       => 'nullable|exists:product_variants,id',
            'items.*.warehouse_id'     => 'required|exists:warehouses,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.unit_price'       => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_percent'      => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            $quote = DB::transaction(function () use ($validated, $request) {
                $quote = SalesQuote::create([
                    'customer_id'  => $validated['customer_id'] ?? null,
                    'user_id'      => $request->user()->id,
                    'warehouse_id' => $validated['warehouse_id'] ?? $request->user()->current_warehouse_id,
                    'date'         => $validated['date'] ?? now(),
                    'valid_until'  => $validated['valid_until'] ?? now()->addDays(15),
                    'status'       => 'draft',
                    'notes'        => $validated['notes'] ?? null,
                ]);

                foreach ($validated['items'] as $itemData) {
                    $quote->items()->create([
                        'product_id'       => $itemData['product_id'],
                        'variant_id'       => $itemData['variant_id'] ?? null,
                        'warehouse_id'     => $itemData['warehouse_id'],
                        'quantity'         => $itemData['quantity'],
                        'unit_price'       => $itemData['unit_price'],
                        'discount_percent' => $itemData['discount_percent'] ?? 0,
                        'tax_percent'      => $itemData['tax_percent'] ?? 0,
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

        $validated = $request->validate([
            'customer_id'              => 'nullable|exists:customers,id',
            'warehouse_id'             => 'nullable|exists:warehouses,id',
            'valid_until'              => 'nullable|date',
            'notes'                    => 'nullable|string',
            'status'                   => 'nullable|in:draft,sent,accepted,rejected',
            'items'                    => 'nullable|array|min:1',
            'items.*.product_id'       => 'required|exists:products,id',
            'items.*.variant_id'       => 'nullable|exists:product_variants,id',
            'items.*.warehouse_id'     => 'required|exists:warehouses,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.unit_price'       => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_percent'      => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            DB::transaction(function () use ($quote, $validated) {
                $quote->update(collect($validated)->only(['customer_id', 'warehouse_id', 'valid_until', 'notes', 'status'])->toArray());

                if (isset($validated['items'])) {
                    $quote->items()->delete();

                    foreach ($validated['items'] as $itemData) {
                        $quote->items()->create([
                            'product_id'       => $itemData['product_id'],
                            'variant_id'       => $itemData['variant_id'] ?? null,
                            'warehouse_id'     => $itemData['warehouse_id'],
                            'quantity'         => $itemData['quantity'],
                            'unit_price'       => $itemData['unit_price'],
                            'discount_percent' => $itemData['discount_percent'] ?? 0,
                            'tax_percent'      => $itemData['tax_percent'] ?? 0,
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
                        'variant_id'       => $qi->variant_id,
                        'warehouse_id'     => $qi->warehouse_id,
                        'quantity'         => $qi->quantity,
                        'unit_price'       => $qi->unit_price,
                        'discount_percent' => $qi->discount_percent,
                        'discount_amount'  => $qi->discount_amount,
                        'tax_percent'      => $qi->tax_percent,
                        'tax_amount'       => $qi->tax_amount,
                        'subtotal'         => $qi->subtotal,
                        'total'            => $qi->total,
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
     * GET /api/sales-quotes/{id}/pdf
     * Generate PDF for the quote
     */
    public function pdf(Request $request, int $id)
    {
        $quote = SalesQuote::with(['customer', 'user', 'warehouse', 'items.product', 'items.variant'])->findOrFail($id);

        $config = config('app');
        $mode = $request->input('mode', 'standard');

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('quotes.a4', [
            'quote'        => $quote,
            'mode'         => $mode,
            'businessName' => $config['business_name'] ?? 'Mi Empresa',
            'businessRfc'  => $config['business_rfc'] ?? '',
            'businessAddr' => $config['business_address'] ?? '',
            'businessPhone'=> $config['business_phone'] ?? '',
        ]);

        $pdf->setPaper('letter');

        return $pdf->stream("cotizacion-{$quote->quote_number}.pdf");
    }
}
