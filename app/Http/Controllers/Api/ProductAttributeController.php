<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
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
            'source' => 'nullable|string|in:custom,category',
            'source_category_id' => 'nullable|integer|exists:categories,id',
        ]);

        unset($validated['variant_id']);
        $validated['variant_id'] = null;

        $this->validateInheritedSelectValue($validated);

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

        $rules = [
            'name' => 'sometimes|string|max:255',
            'value' => 'sometimes|string|max:255',
            'unit' => 'nullable|string|max:50',
        ];

        // Prevent name change on inherited attributes
        if ($attribute->source === 'category' && $request->has('name')) {
            unset($rules['name']);
        }

        $validated = $request->validate($rules);
        $nextAttribute = array_merge($attribute->only([
            'name',
            'value',
            'source',
            'source_category_id',
        ]), $validated);

        $this->validateInheritedSelectValue($nextAttribute);

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

        if ($attribute->source === 'category') {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar un atributo heredado de categoría. Modifique la categoría para cambiar este atributo.',
            ], 409);
        }

        $attribute->delete();

        return response()->json([
            'message' => 'Atributo eliminado exitosamente',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attribute
     */
    private function validateInheritedSelectValue(array $attribute): void
    {
        if (($attribute['source'] ?? 'custom') !== 'category' || empty($attribute['source_category_id'])) {
            return;
        }

        $category = Category::find($attribute['source_category_id']);
        if (! $category) {
            return;
        }

        $attributeName = $this->attributeNameKey((string) ($attribute['name'] ?? ''));
        $attributeValue = (string) ($attribute['value'] ?? '');

        foreach (($category->default_attributes ?? []) as $definition) {
            if ($this->attributeNameKey((string) ($definition['name'] ?? '')) !== $attributeName) {
                continue;
            }

            $options = $definition['options'] ?? null;
            if (($definition['type'] ?? 'string') !== 'select' || ! is_array($options) || count($options) === 0) {
                return;
            }

            if (! in_array($attributeValue, $options, true)) {
                throw new \Illuminate\Validation\ValidationException(
                    validator([], []),
                    response()->json([
                        'message' => 'El valor "'.$attributeValue.'" no es valido para el atributo "'.$attribute['name'].'"',
                        'errors' => [
                            'value' => [
                                'El atributo "'.$attribute['name'].'" debe ser uno de: '.implode(', ', $options),
                            ],
                        ],
                    ], 422)
                );
            }

            return;
        }
    }

    private function attributeNameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
