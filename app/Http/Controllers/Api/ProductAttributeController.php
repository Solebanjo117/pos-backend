<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
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
     * If variant_id is null (product-level), automatically copies to all existing variants.
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

        // If this is a product-level attribute (no variant_id), copy it to all existing variants
        if (empty($validated['variant_id'])) {
            $variants = ProductVariant::where('product_id', $validated['product_id'])->get();

            foreach ($variants as $variant) {
                // Check if variant already has this attribute (by name) to avoid duplicates
                $exists = ProductAttribute::where('product_id', $validated['product_id'])
                    ->where('variant_id', $variant->id)
                    ->where('name', $validated['name'])
                    ->exists();

                if (!$exists) {
                    ProductAttribute::create([
                        'product_id' => $validated['product_id'],
                        'variant_id' => $variant->id,
                        'name'       => $validated['name'],
                        'value'      => $validated['value'],
                        'unit'       => $validated['unit'],
                    ]);
                }
            }
        }

        return response()->json([
            'message' => 'Atributo agregado exitosamente',
            'data'    => $attribute,
        ], 201);
    }

    /**
     * Update an attribute.
     * If updating a product-level attribute, updates all variant copies as well.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $attribute = ProductAttribute::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'value' => 'sometimes|string|max:255',
            'unit' => 'nullable|string|max:50',
        ]);

        $oldName = $attribute->name;
        $attribute->update($validated);

        // If this is a product-level attribute, update all variant copies as well
        if (empty($attribute->variant_id)) {
            ProductAttribute::where('product_id', $attribute->product_id)
                ->whereNotNull('variant_id')
                ->where('name', $oldName)
                ->update([
                    'name' => $validated['name'] ?? $oldName,
                    'value' => $validated['value'] ?? $attribute->value,
                    'unit' => $validated['unit'] ?? $attribute->unit,
                ]);
        }

        return response()->json([
            'message' => 'Atributo actualizado exitosamente',
            'data' => $attribute,
        ]);
    }

    /**
     * Remove an attribute.
     * If deleting a product-level attribute, also removes all variant copies.
     */
    public function destroy(string $id): JsonResponse
    {
        $attribute = ProductAttribute::findOrFail($id);

        // If this is a product-level attribute, delete all variant copies as well
        if (empty($attribute->variant_id)) {
            ProductAttribute::where('product_id', $attribute->product_id)
                ->whereNotNull('variant_id')
                ->where('name', $attribute->name)
                ->delete();
        }

        $attribute->delete();

        return response()->json([
            'message' => 'Atributo eliminado exitosamente',
        ]);
    }
}
