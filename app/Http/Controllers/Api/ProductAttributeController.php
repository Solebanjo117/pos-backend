<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductAttributeController extends Controller
{
    /**
     * Get attributes for a specific product.
     * With ?variant_id=X returns only that variant's specific attrs.
     * Without it returns product-level attrs (variant_id IS NULL).
     */
    public function index(string $productId, Request $request): JsonResponse
    {
        $query = ProductAttribute::where('product_id', $productId)
            ->orderBy('name');

        if ($request->has('variant_id')) {
            $query->where('variant_id', $request->integer('variant_id'));
        } else {
            $query->whereNull('variant_id');
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * Store a new attribute for a product (optionally scoped to a variant).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'name'       => 'required|string|max:255',
            'value'      => 'required|string|max:255',
            'unit'       => 'nullable|string|max:50',
        ]);

        $attribute = ProductAttribute::create($validated);

        return response()->json([
            'message' => 'Atributo agregado exitosamente',
            'data'    => $attribute,
        ], 201);
    }

    /**
     * Update an attribute.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $attribute = ProductAttribute::findOrFail($id);

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
     * Remove an attribute.
     */
    public function destroy(string $id): JsonResponse
    {
        $attribute = ProductAttribute::findOrFail($id);
        $attribute->delete();

        return response()->json([
            'message' => 'Atributo eliminado exitosamente',
        ]);
    }
}
