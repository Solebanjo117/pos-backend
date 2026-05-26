<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    /**
     * GET /api/v1/brands
     * List all brands with filters and pagination
     */
    public function index(Request $request): JsonResponse
    {
        $query = Brand::query();

        // Search by name
        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->search.'%');
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');
        $query->orderBy($sortBy, $sortDirection);

        // Include product count
        $query->with('suppliers:id,name');
        $query->withCount(['productVariants', 'suppliers']);

        // Paginate
        $perPage = $request->get('per_page', 15);
        $brands = $query->paginate($perPage);

        return response()->json($brands);
    }

    /**
     * GET /api/v1/brands/all
     * Get all brands (no pagination, for dropdowns)
     */
    public function all(): JsonResponse
    {
        $brands = Brand::orderBy('name')
            ->get(['id', 'name', 'logo_url']);

        return response()->json(['data' => $brands]);
    }

    /**
     * GET /api/v1/brands/{id}
     * Get single brand with related data
     */
    public function show(int $id): JsonResponse
    {
        $brand = Brand::with('suppliers:id,name,contact_name,phone')
            ->withCount(['productVariants', 'suppliers'])
            ->findOrFail($id);

        return response()->json(['data' => $this->enrichBrandResponse($brand)]);
    }

    /**
     * POST /api/v1/brands
     * Create new brand
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:brands,name',
            'logo_url' => 'nullable|url|max:500',
            'status' => 'nullable|string|in:active,inactive',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string|max:1000',
            'supplier_ids' => 'nullable|array',
            'supplier_ids.*' => 'integer|exists:suppliers,id',
        ]);

        $hasSupplierIds = array_key_exists('supplier_ids', $validated);
        $supplierIds = $validated['supplier_ids'] ?? [];
        unset($validated['supplier_ids']);

        $brand = Brand::create($validated);

        if ($hasSupplierIds) {
            $brand->suppliers()->sync($supplierIds);
        }

        $brand->load('suppliers:id,name,contact_name,phone');

        $response = [
            'message' => 'Marca creada exitosamente',
            'data' => $this->enrichBrandResponse($brand),
        ];

        if (! $hasSupplierIds || empty($supplierIds)) {
            $response['warning'] = 'No se asignaron proveedores a la marca.';
        }

        return response()->json($response, 201);
    }

    /**
     * PUT /api/v1/brands/{id}
     * Update existing brand
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $brand = Brand::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:brands,name,'.$id,
            'logo_url' => 'nullable|url|max:500',
            'status' => 'nullable|string|in:active,inactive',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string|max:1000',
            'supplier_ids' => 'nullable|array',
            'supplier_ids.*' => 'integer|exists:suppliers,id',
        ]);

        $hasSupplierIds = array_key_exists('supplier_ids', $validated);
        $supplierIds = $validated['supplier_ids'] ?? [];
        unset($validated['supplier_ids']);

        $brand->update($validated);

        if ($hasSupplierIds) {
            $brand->suppliers()->sync($supplierIds);
        }

        return response()->json([
            'message' => 'Marca actualizada exitosamente',
            'data' => $this->enrichBrandResponse($brand->fresh()->load('suppliers:id,name,contact_name,phone')),
        ]);
    }

    /**
     * DELETE /api/v1/brands/{id}
     * Delete brand (soft delete if configured)
     */
    public function destroy(int $id): JsonResponse
    {
        $brand = Brand::findOrFail($id);

        // Check if brand is used by products
        if ($brand->productVariants()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: existen productos asociados a esta marca',
            ], 422);
        }

        $brand->delete();

        return response()->json([
            'message' => 'Marca eliminada exitosamente',
        ]);
    }

    /**
     * POST /api/v1/brands/{id}/suppliers
     * Attach a supplier to a brand.
     */
    public function attachSupplier(Request $request, int $id): JsonResponse
    {
        $brand = Brand::findOrFail($id);

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
        ]);

        $brand->suppliers()->syncWithoutDetaching([$validated['supplier_id']]);

        return response()->json([
            'message' => 'Proveedor asociado a la marca exitosamente',
            'data' => $this->enrichBrandResponse($brand->fresh()->load('suppliers:id,name,contact_name,phone')),
        ]);
    }

    /**
     * DELETE /api/v1/brands/{id}/suppliers/{supplierId}
     * Detach a supplier from a brand.
     */
    public function detachSupplier(int $id, int $supplierId): JsonResponse
    {
        $brand = Brand::findOrFail($id);
        $brand->suppliers()->detach($supplierId);

        return response()->json([
            'message' => 'Proveedor desasociado de la marca exitosamente',
            'data' => $this->enrichBrandResponse($brand->fresh()->load('suppliers:id,name,contact_name,phone')),
        ]);
    }

    /**
     * Enrich brand response with supplier contact details.
     */
    private function enrichBrandResponse(Brand $brand): array
    {
        $data = $brand->toArray();
        $data['suppliers'] = $brand->suppliers->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'contact_name' => $s->contact_name,
            'phone' => $s->phone,
        ])->values()->all();

        return $data;
    }
}
