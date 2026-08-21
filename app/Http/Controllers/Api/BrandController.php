<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBrandRequest;
use App\Http\Requests\UpdateBrandRequest;
use App\Models\Brand;
use Illuminate\Database\QueryException;
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
        $canManage = in_array($request->user()?->role, ['admin', 'manager'], true);
        $query = Brand::query();

        // Search by name
        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->search.'%');
        }

        // Sorting
        $sortBy = in_array($request->get('sort_by'), ['name', 'created_at', 'status'], true) ? $request->get('sort_by') : 'name';
        $sortDirection = $request->get('sort_direction') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortBy, $sortDirection);

        // Include product count
        $query->with('suppliers:id,name');
        $query->withCount(['productVariants', 'suppliers']);
        $query->withExists(['productVariants as has_product_variants', 'suppliers as has_suppliers']);

        // Paginate
        $perPage = min(100, max(5, (int) $request->get('per_page', 15)));
        $brands = $query->paginate($perPage);
        $brands->getCollection()->transform(function (Brand $brand) use ($canManage): Brand {
            $reason = $brand->has_product_variants
                ? 'Tiene productos o variantes relacionadas.'
                : ($brand->has_suppliers ? 'Tiene proveedores relacionados.' : null);
            $brand->setAttribute('has_products', (bool) $brand->has_product_variants);
            $brand->setAttribute('can_edit', $canManage);
            $brand->setAttribute('can_delete', $canManage && $reason === null);
            $brand->setAttribute('delete_block_reason', $canManage ? $reason : 'No tienes permiso para eliminar marcas.');

            return $brand;
        });

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
    public function store(StoreBrandRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $hasSupplierIds = array_key_exists('supplier_ids', $validated);
        $supplierIds = $validated['supplier_ids'] ?? [];
        unset($validated['supplier_ids']);

        try {
            $brand = Brand::create($validated);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                return response()->json(['message' => 'Ya existe una marca con ese nombre.', 'errors' => ['name' => ['Ya existe una marca con ese nombre.']]], 409);
            }
            throw $exception;
        }

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
    public function update(UpdateBrandRequest $request, int $id): JsonResponse
    {
        $brand = Brand::findOrFail($id);

        $validated = $request->validated();

        $hasSupplierIds = array_key_exists('supplier_ids', $validated);
        $supplierIds = $validated['supplier_ids'] ?? [];
        unset($validated['supplier_ids']);

        try {
            $brand->update($validated);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                return response()->json(['message' => 'Ya existe una marca con ese nombre.', 'errors' => ['name' => ['Ya existe una marca con ese nombre.']]], 409);
            }
            throw $exception;
        }

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

        if ($brand->suppliers()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: existen proveedores asociados a esta marca',
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
