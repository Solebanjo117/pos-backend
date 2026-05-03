<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpsertSupplierVariantRequest;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            ->with('brands:id,name')
            ->findOrFail($id);

        // Load products with pricing
        $products = $supplier->productSuppliers()
            ->with(['product.pricing', 'variant.brand', 'variant.pricing', 'variant.inventory'])
            ->get()
            ->map(function ($ps) {
                return [
                    'id' => $ps->id,
                    'product_id' => $ps->product_id,
                    'variant_id' => $ps->variant_id,
                    'product_name' => $ps->product->name ?? 'N/A',
                    'product_sku' => $ps->variant?->sku ?? $ps->product->sku_base ?? 'N/A',
                    'brand_name' => $ps->variant?->brand?->name ?? 'N/A',
                    'supplier_cost' => $ps->supplier_cost,
                    'lead_time_days' => $ps->lead_time_days,
                    'minimum_order_quantity' => $ps->minimum_order_quantity,
                    'is_preferred' => $ps->is_preferred,
                    'total_stock' => $ps->variant?->inventory?->sum('quantity_available') ?? 0,
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
    public function store(StoreSupplierRequest $request): JsonResponse
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

    /**
     * GET /api/v1/suppliers/{id}/brands
     * Get brands associated with a supplier.
     */
    public function brands(int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $brands = $supplier->brands()->orderBy('brands.name')->get(['brands.id', 'brands.name']);

        return response()->json(['data' => $brands]);
    }

    /**
     * POST /api/v1/suppliers/{id}/brands
     * Attach a brand to a supplier.
     */
    public function attachBrand(Request $request, int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);

        $validated = $request->validate([
            'brand_id' => 'required|exists:brands,id',
        ]);

        $supplier->brands()->syncWithoutDetaching([$validated['brand_id']]);

        return response()->json([
            'message' => 'Marca asociada al proveedor exitosamente',
            'data' => $supplier->fresh()->load('brands:id,name'),
        ]);
    }

    /**
     * DELETE /api/v1/suppliers/{id}/brands/{brandId}
     * Detach a brand from a supplier.
     */
    public function detachBrand(int $id, int $brandId): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->brands()->detach($brandId);

        return response()->json([
            'message' => 'Marca desasociada del proveedor exitosamente',
            'data' => $supplier->fresh()->load('brands:id,name'),
        ]);
    }

    public function variants(int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);

        return response()->json([
            'data' => $this->supplierVariantRows($supplier),
        ]);
    }

    public function attachVariant(UpsertSupplierVariantRequest $request, int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $validated = $request->validated();
        $variant = ProductVariant::with('brand')->findOrFail($validated['variant_id']);

        if ($validated['is_preferred'] ?? false) {
            ProductSupplier::query()
                ->where('variant_id', $variant->id)
                ->where('supplier_id', '!=', $supplier->id)
                ->update(['is_preferred' => false]);
        }

        ProductSupplier::query()->updateOrCreate(
            [
                'variant_id' => $variant->id,
                'supplier_id' => $supplier->id,
            ],
            [
                'product_id' => $variant->product_id,
                'supplier_cost' => $validated['supplier_cost'],
                'lead_time_days' => $validated['lead_time_days'] ?? $supplier->lead_time_days ?? 0,
                'minimum_order_quantity' => $validated['minimum_order_quantity'] ?? 1,
                'is_preferred' => $validated['is_preferred'] ?? false,
            ]
        );

        if ($variant->brand_id) {
            $supplier->brands()->syncWithoutDetaching([$variant->brand_id]);
        }

        return response()->json([
            'message' => 'Variante asociada al proveedor exitosamente',
            'data' => $this->supplierVariantRows($supplier),
        ], 201);
    }

    public function updateVariant(UpsertSupplierVariantRequest $request, int $id, int $variantId): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $validated = $request->safe()->except('variant_id');

        $productSupplier = ProductSupplier::query()
            ->where('supplier_id', $supplier->id)
            ->where('variant_id', $variantId)
            ->firstOrFail();

        if ($validated['is_preferred'] ?? false) {
            ProductSupplier::query()
                ->where('variant_id', $variantId)
                ->where('supplier_id', '!=', $supplier->id)
                ->update(['is_preferred' => false]);
        }

        $productSupplier->update($validated);

        return response()->json([
            'message' => 'Variante suministrada actualizada exitosamente',
            'data' => $this->supplierVariantRows($supplier),
        ]);
    }

    public function detachVariant(int $id, int $variantId): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);

        ProductSupplier::query()
            ->where('supplier_id', $supplier->id)
            ->where('variant_id', $variantId)
            ->delete();

        return response()->json([
            'message' => 'Variante desasociada del proveedor exitosamente',
            'data' => $this->supplierVariantRows($supplier),
        ]);
    }

    private function supplierVariantRows(Supplier $supplier): array
    {
        return $supplier->productSuppliers()
            ->whereNotNull('variant_id')
            ->with(['product:id,name,sku_base', 'variant.brand:id,name', 'variant.pricing:id,variant_id,cost', 'variant.inventory'])
            ->orderBy('id')
            ->get()
            ->map(fn (ProductSupplier $ps) => [
                'id' => $ps->id,
                'product_id' => $ps->product_id,
                'variant_id' => $ps->variant_id,
                'product_name' => $ps->product?->name ?? 'Producto',
                'product_sku' => $ps->variant?->sku ?? $ps->product?->sku_base,
                'brand_name' => $ps->variant?->brand?->name,
                'supplier_cost' => (float) $ps->supplier_cost,
                'lead_time_days' => $ps->lead_time_days,
                'minimum_order_quantity' => $ps->minimum_order_quantity,
                'is_preferred' => (bool) $ps->is_preferred,
                'total_stock' => (int) ($ps->variant?->inventory?->sum('quantity_available') ?? 0),
            ])
            ->values()
            ->all();
    }
}
