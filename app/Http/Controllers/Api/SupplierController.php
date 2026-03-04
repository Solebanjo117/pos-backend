<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SupplierController extends Controller
{
    /**
     * GET /api/v1/suppliers
     * List all suppliers with filters
     */
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query();

        // Search
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');
        $query->orderBy($sortBy, $sortDirection);

        // Include counts
        $query->withCount('productSuppliers as products_count');

        // Paginate
        $perPage = $request->get('per_page', 15);
        $suppliers = $query->paginate($perPage);

        return response()->json($suppliers);
    }

    /**
     * GET /api/v1/suppliers/{id}
     * Get single supplier with related data
     */
    public function show(int $id): JsonResponse
    {
        $supplier = Supplier::withCount('productSuppliers as products_count')
            ->findOrFail($id);

        // Load products with pricing
        $products = $supplier->productSuppliers()
            ->with(['product.pricing', 'product.brand'])
            ->get()
            ->map(function ($ps) {
                return [
                    'id' => $ps->id,
                    'product_id' => $ps->product_id,
                    'product_name' => $ps->product->name ?? 'N/A',
                    'product_sku' => $ps->product->sku ?? 'N/A',
                    'brand_name' => $ps->product->brand->name ?? 'N/A',
                    'supplier_cost' => $ps->supplier_cost,
                    'lead_time_days' => $ps->lead_time_days,
                    'minimum_order_quantity' => $ps->minimum_order_quantity,
                    'is_preferred' => $ps->is_preferred,
                ];
            });

        return response()->json([
            'data' => $supplier,
            'products' => $products,
        ]);
    }

    /**
     * POST /api/v1/suppliers
     * Create new supplier
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'payment_terms' => 'nullable|string|max:255',
            'lead_time_days' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        $supplier = Supplier::create($validated);

        return response()->json([
            'message' => 'Proveedor creado exitosamente',
            'data' => $supplier,
        ], 201);
    }

    /**
     * PUT /api/v1/suppliers/{id}
     * Update existing supplier
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'payment_terms' => 'nullable|string|max:255',
            'lead_time_days' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        $supplier->update($validated);

        return response()->json([
            'message' => 'Proveedor actualizado exitosamente',
            'data' => $supplier->fresh(),
        ]);
    }

    /**
     * DELETE /api/v1/suppliers/{id}
     * Soft delete supplier
     */
    public function destroy(int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);

        // Check for active purchase orders
        if ($supplier->purchaseOrders()->whereNotIn('status', ['received', 'cancelled'])->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: tiene órdenes de compra activas',
            ], 422);
        }

        $supplier->delete();

        return response()->json([
            'message' => 'Proveedor eliminado exitosamente',
        ]);
    }

    /**
     * GET /api/v1/suppliers/all
     * Get all suppliers (no pagination, for dropdowns)
     */
    public function all(): JsonResponse
    {
        $suppliers = Supplier::active()
            ->orderBy('name')
            ->select('id', 'name', 'contact_name', 'email', 'phone')
            ->get();

        return response()->json(['data' => $suppliers]);
    }

    /**
     * GET /api/v1/suppliers/{id}/purchase-orders
     * Get purchase orders for a supplier
     */
    public function purchaseOrders(int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);

        $orders = $supplier->purchaseOrders()
            ->with('warehouse')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($orders);
    }
}
