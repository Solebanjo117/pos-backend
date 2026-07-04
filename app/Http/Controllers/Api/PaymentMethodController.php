<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'context' => 'nullable|string|in:'.implode(',', PaymentMethod::contextOptions()),
            'real_money' => 'nullable|boolean',
        ]);

        $methods = PaymentMethod::active()
            ->notArchived()
            ->forContext($validated['context'] ?? null)
            ->when(
                ($validated['context'] ?? null) === PaymentMethod::CONTEXT_SALES,
                fn ($query) => $query->where('code', '!=', 'supplier_credit'),
            )
            ->when($request->boolean('real_money'), function ($query): void {
                $query->whereNotIn('code', PaymentMethod::DEFERRED_CODES);
            })
            ->ordered()
            ->get();

        return response()->json($methods);
    }

    public function all(): JsonResponse
    {
        return response()->json(PaymentMethod::notArchived()->ordered()->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'is_active' => 'sometimes|boolean',
            'requires_reference' => 'boolean',
            'requires_cash_count' => 'boolean',
            'liquidity_bucket' => 'sometimes|string|in:cash,bank,credit,store_credit',
            'available_in_sales' => 'sometimes|boolean',
            'available_in_customer_receipts' => 'sometimes|boolean',
            'available_in_supplier_payments' => 'sometimes|boolean',
            'available_in_purchase_orders' => 'sometimes|boolean',
            'available_in_returns' => 'sometimes|boolean',
        ]);

        $code = PaymentMethod::generateCode($validated['name']);
        $existing = PaymentMethod::where('code', $code)->first();

        if ($existing) {
            return response()->json([
                'message' => $existing->is_archived
                    ? 'Ya existe un metodo de pago archivado con ese codigo. No se creo un duplicado.'
                    : 'Ya existe un metodo de pago con ese codigo.',
                'errors' => ['name' => ['Un metodo con nombre similar ya existe.']],
            ], 422);
        }

        $method = PaymentMethod::create([
            'name' => $validated['name'],
            'code' => $code,
            'is_active' => $validated['is_active'] ?? true,
            'is_system' => false,
            'requires_reference' => $validated['requires_reference'] ?? false,
            'requires_cash_count' => $validated['requires_cash_count'] ?? false,
            'liquidity_bucket' => $validated['liquidity_bucket'] ?? PaymentMethod::LIQUIDITY_BANK,
            'available_in_sales' => $validated['available_in_sales'] ?? true,
            'available_in_customer_receipts' => $validated['available_in_customer_receipts'] ?? false,
            'available_in_supplier_payments' => $validated['available_in_supplier_payments'] ?? false,
            'available_in_purchase_orders' => $validated['available_in_purchase_orders'] ?? false,
            'available_in_returns' => $validated['available_in_returns'] ?? false,
            'sort_order' => (PaymentMethod::max('sort_order') ?? 0) + 1,
        ]);

        return response()->json($method, 201);
    }

    public function update(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'is_active' => 'sometimes|boolean',
            'requires_reference' => 'sometimes|boolean',
            'requires_cash_count' => 'sometimes|boolean',
            'liquidity_bucket' => 'sometimes|string|in:cash,bank,credit,store_credit',
            'available_in_sales' => 'sometimes|boolean',
            'available_in_customer_receipts' => 'sometimes|boolean',
            'available_in_supplier_payments' => 'sometimes|boolean',
            'available_in_purchase_orders' => 'sometimes|boolean',
            'available_in_returns' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        if ($paymentMethod->is_system) {
            $validated = array_intersect_key($validated, [
                'is_active' => true,
                'requires_reference' => true,
                'requires_cash_count' => true,
                'liquidity_bucket' => true,
                'available_in_sales' => true,
                'available_in_customer_receipts' => true,
                'available_in_supplier_payments' => true,
                'available_in_purchase_orders' => true,
                'available_in_returns' => true,
                'sort_order' => true,
            ]);
        }

        $paymentMethod->update($validated);

        return response()->json($paymentMethod->fresh());
    }

    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        if ($paymentMethod->is_system) {
            return response()->json([
                'message' => 'Los metodos de sistema no pueden ser eliminados.',
            ], 403);
        }

        $paymentMethod->update([
            'is_active' => false,
            'archived_at' => now(),
        ]);

        return response()->json(['message' => 'Metodo de pago archivado.']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order' => 'required|array',
            'order.*' => 'integer|exists:payment_methods,id',
        ]);

        foreach ($validated['order'] as $index => $id) {
            PaymentMethod::where('id', $id)->update(['sort_order' => $index]);
        }

        return response()->json(['message' => 'Orden actualizado.']);
    }
}
