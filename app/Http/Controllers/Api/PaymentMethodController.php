<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    /**
     * GET /api/v1/payment-methods
     * List active payment methods (all authenticated users)
     */
    public function index(): JsonResponse
    {
        $methods = PaymentMethod::active()
            ->ordered()
            ->get();

        return response()->json($methods);
    }

    /**
     * GET /api/v1/payment-methods/all
     * List ALL payment methods including inactive (admin/manager only)
     */
    public function all(): JsonResponse
    {
        $methods = PaymentMethod::ordered()->get();

        return response()->json($methods);
    }

    /**
     * POST /api/v1/payment-methods
     * Create new payment method (admin/manager only)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'requires_reference' => 'boolean',
            'requires_cash_count' => 'boolean',
        ]);

        // Generate code from name
        $code = PaymentMethod::generateCode($validated['name']);

        // Check uniqueness
        if (PaymentMethod::where('code', $code)->exists()) {
            return response()->json([
                'message' => 'Ya existe un método de pago con ese código.',
                'errors' => ['name' => ['Un método con nombre similar ya existe.']],
            ], 422);
        }

        // Get max sort_order
        $maxOrder = PaymentMethod::max('sort_order') ?? 0;

        $method = PaymentMethod::create([
            'name' => $validated['name'],
            'code' => $code,
            'is_active' => true,
            'is_system' => false,
            'requires_reference' => $validated['requires_reference'] ?? false,
            'requires_cash_count' => $validated['requires_cash_count'] ?? false,
            'sort_order' => $maxOrder + 1,
        ]);

        return response()->json($method, 201);
    }

    /**
     * PUT /api/v1/payment-methods/{id}
     * Update payment method (admin/manager only)
     */
    public function update(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        // System methods: cannot change is_active
        if ($paymentMethod->is_system && $request->has('is_active')) {
            return response()->json([
                'message' => 'Los métodos de sistema no pueden ser desactivados.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'is_active' => 'sometimes|boolean',
            'requires_reference' => 'sometimes|boolean',
            'requires_cash_count' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        // System methods: only allow sort_order changes
        if ($paymentMethod->is_system) {
            $validated = array_intersect_key($validated, ['sort_order' => true]);
        }

        $paymentMethod->update($validated);

        return response()->json($paymentMethod);
    }

    /**
     * DELETE /api/v1/payment-methods/{id}
     * Archive (soft delete) payment method (admin/manager only)
     */
    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        // System methods cannot be deleted
        if ($paymentMethod->is_system) {
            return response()->json([
                'message' => 'Los métodos de sistema no pueden ser eliminados.',
            ], 403);
        }

        // Soft delete = set is_active to false
        $paymentMethod->update(['is_active' => false]);

        return response()->json(['message' => 'Método de pago archivado.']);
    }

    /**
     * POST /api/v1/payment-methods/reorder
     * Reorder payment methods (admin/manager only)
     */
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
