<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
            $query->where('name', 'ilike', '%' . $request->search . '%');
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');
        $query->orderBy($sortBy, $sortDirection);

        // Include product count
        $query->withCount('productVariants');

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
        $brand = Brand::withCount('productVariants')
            ->findOrFail($id);

        return response()->json(['data' => $brand]);
    }

    /**
     * POST /api/v1/brands
     * Create new brand
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:brands,name',
            'prefix' => 'nullable|string|max:5|unique:brands,prefix',
            'logo_url' => 'nullable|url|max:500',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $brand = Brand::create($validated);

        return response()->json([
            'message' => 'Marca creada exitosamente',
            'data' => $brand,
        ], 201);
    }

    /**
     * PUT /api/v1/brands/{id}
     * Update existing brand
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $brand = Brand::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:brands,name,' . $id,
            'prefix' => 'nullable|string|max:5|unique:brands,prefix,' . $id,
            'logo_url' => 'nullable|url|max:500',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $brand->update($validated);

        return response()->json([
            'message' => 'Marca actualizada exitosamente',
            'data' => $brand->fresh(),
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
}
