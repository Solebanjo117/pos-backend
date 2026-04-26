<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductAttribute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductAttributeController extends Controller
{
    /**
     * Get product-level attributes for a product.
     */
    public function index(string $productId, Request $request): JsonResponse
    {
        $request->validate([
            'variant_id' => 'missing',
        ]);

        $attributes = ProductAttribute::where('product_id', $productId)
            ->whereNull('variant_id')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $attributes]);
    }

    /**
     * Store a new product-level attribute.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'missing',
            'name' => 'required|string|max:255',
            'value' => 'required|string|max:255',
            'unit' => 'nullable|string|max:50',
        ]);

        unset($validated['variant_id']);
        $validated['variant_id'] = null;

        $attribute = ProductAttribute::create($validated);

        return response()->json([
            'message' => 'Atributo agregado exitosamente',
            'data' => $attribute,
        ], 201);
    }

    /**
     * Update a product-level attribute.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $attribute = ProductAttribute::whereNull('variant_id')->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'value' => 'sometimes|string|max:255',
            'unit' => 'nullable|string|max:50',
        ]);

        $attribute->update($validated);

        return response()->json([
            'message' => 'Atributo actualizado exitosamente',
            'data' => $attribute,
        ]);
    }

    /**
     * Remove a product-level attribute.
     */
    public function destroy(string $id): JsonResponse
    {
        $attribute = ProductAttribute::whereNull('variant_id')->findOrFail($id);
        $attribute->delete();

        return response()->json([
            'message' => 'Atributo eliminado exitosamente',
        ]);
    }
}
