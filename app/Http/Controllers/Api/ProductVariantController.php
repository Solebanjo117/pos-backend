<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductVariantController extends Controller
{
    public function __construct(
        private readonly ProductService $productService
    ) {}

    /**
     * Display a listing of variants for a product.
     */
    public function index(Product $product): JsonResponse
    {
        $variants = $product->variants()
            ->with(['brand', 'pricing', 'inventory.warehouse', 'attributes'])
            ->get();

        return response()->json([
            'data' => $variants
        ]);
    }

    /**
     * Store a newly created variant.
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'brand_id' => 'required|exists:brands,id',
            'sku' => [
                'required', 
                'string', 
                'max:255'
            ],
            'upc' => [
                'nullable', 
                'string', 
                'max:50'
            ],
            'status' => 'nullable|in:active,discontinued,pending',

            // Pricing (optional)
            'pricing' => 'nullable|array',
            'pricing.base_price' => 'required_with:pricing|numeric|min:0',
            'pricing.msrp' => 'nullable|numeric|min:0',
            'pricing.cost' => 'nullable|numeric|min:0',
            'pricing.tax_rate' => 'nullable|numeric|min:0|max:100',
            'pricing.max_discount_percentage' => 'nullable|numeric|min:0|max:100',
            'pricing.pricing_formula' => 'nullable|string|max:255',

            // Initial Inventory (optional)
            'initial_inventory' => 'nullable|array',
            'initial_inventory.*.warehouse_id' => 'required|exists:warehouses,id',
            'initial_inventory.*.stock_available' => 'required|integer|min:0',
            'initial_inventory.*.reorder_point' => 'nullable|integer|min:0',
            'initial_inventory.*.reorder_quantity' => 'nullable|integer|min:0',

            // Attributes (variant-level specs)
            'attributes' => 'nullable|array',
            'attributes.*.name' => 'required_with:attributes|string|max:100',
            'attributes.*.value' => 'required_with:attributes|string|max:255',
            'attributes.*.unit' => 'nullable|string|max:50',
        ]);

        try {
            // Create variant using service (reusing logic creates pricing/inventory)
            // We need to adapt the structure slightly for helper method if needed, 
            // but ProductService::createVariant is simple. Let's use it or extend it.
            // ProductService::createVariant doesn't handle pricing/inventory in one go 
            // inside the *method* shown earlier, it returned the variant.
            // Let's implement the full transaction here or call a service method if we add one.
            // For now, I'll implement logic here wrapping service calls or just using models 
            // since ProductService::createVariant didn't include pricing/inventory logic in the snippet I saw?
            // Wait, looking at ProductService.php again...
            // createProduct DOES it all. createVariant ONLY creates the variant model.
            // I should handle pricing and inventory creation here.
            
            \DB::beginTransaction();

            $variant = $this->productService->createVariant($product, [
                'brand_id' => $validated['brand_id'],
                'sku' => $validated['sku'],
                'upc' => $validated['upc'] ?? null,
                'pricing' => $validated['pricing'] ?? null,
                'status' => $validated['status'] ?? 'active',
                'is_default' => false,
            ]);


            // Add Initial Inventory
            if (!empty($validated['initial_inventory'])) {
                foreach ($validated['initial_inventory'] as $invData) {
                    $variant->inventory()->create([
                        'warehouse_id' => $invData['warehouse_id'],
                        'quantity_available' => $invData['stock_available'] ?? 0,
                        'reorder_point' => $invData['reorder_point'] ?? 0,
                        'reorder_quantity' => $invData['reorder_quantity'] ?? 0,
                        'product_id' => $product->id, // Fill product_id as well
                    ]);
                }
            } else {
                // Create empty inventory record for default warehouse (usually ID 1) if none provided
                // This ensures it shows up in stock lists
                $variant->inventory()->create([
                    'warehouse_id' => 1,
                    'quantity_available' => 0,
                    'reorder_point' => 5,
                    'reorder_quantity' => 10,
                    'product_id' => $product->id,
                ]);
            }

            // Create variant-level pricing (always — either from request or copied from product)
            $pricingData = $validated['pricing'] ?? null;

            if (!$pricingData) {
                // Copy product's current pricing as independent variant pricing
                $productPricing = $product->pricing;
                if ($productPricing) {
                    $pricingData = [
                        'base_price' => $productPricing->base_price,
                        'msrp' => $productPricing->msrp,
                        'cost' => $productPricing->cost,
                        'tax_rate' => $productPricing->tax_rate,
                        'max_discount_percentage' => $productPricing->max_discount_percentage,
                    ];
                }
            }

            if ($pricingData) {
                $pricingData['variant_id'] = $variant->id;
                $pricingData['product_id'] = $product->id;
                $pricing = $variant->pricing()->create($pricingData);
                $pricing->calculateMargin();
                $pricing->save();
            }

            \DB::commit();

            // Assign variant-level attributes (outside transaction for simplicity)
            // R27: If no attributes provided, inherit from parent product
            if (!empty($validated['attributes'])) {
                $this->productService->assignVariantAttributes($variant, $validated['attributes']);
            } else {
                // Inherit attributes from parent product if available
                $parentAttributes = $product->attributes;
                if ($parentAttributes->isNotEmpty()) {
                    $attributesData = $parentAttributes->map(function ($attr) {
                        return [
                            'name' => $attr->name,
                            'value' => $attr->value,
                            'unit' => $attr->unit,
                        ];
                    })->toArray();
                    $this->productService->assignVariantAttributes($variant, $attributesData);
                }
            }

            return response()->json([
                'message' => 'Variante creada exitosamente',
                'data' => $variant->load(['brand', 'pricing', 'inventory', 'attributes'])
            ], 201);

        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json([
                'message' => 'Error al crear la variante: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified variant.
     */
    public function update(Request $request, Product $product, ProductVariant $variant): JsonResponse
    {
        // Ensure variant belongs to product
        if ($variant->product_id !== $product->id) {
            return response()->json(['message' => 'Variante no corresponde al producto'], 404);
        }

        $validated = $request->validate([
            'sku' => [
                'nullable', 
                'string', 
                'max:255'
            ],
            'upc' => [
                'nullable', 
                'string', 
                'max:50'
            ],
            'status' => 'nullable|in:active,discontinued,pending',

            // Pricing (optional)
            'pricing' => 'nullable|array',
            'pricing.base_price' => 'required_with:pricing|numeric|min:0',
            'pricing.msrp' => 'nullable|numeric|min:0',
            'pricing.cost' => 'nullable|numeric|min:0',
            'pricing.tax_rate' => 'nullable|numeric|min:0|max:100',
            'pricing.max_discount_percentage' => 'nullable|numeric|min:0|max:100',
            'pricing.pricing_formula' => 'nullable|string|max:255',

            // Attributes (variant-level specs)
            'attributes' => 'nullable|array',
            'attributes.*.name' => 'required_with:attributes|string|max:100',
            'attributes.*.value' => 'required_with:attributes|string|max:255',
            'attributes.*.unit' => 'nullable|string|max:50',
        ]);

        try {
            $this->productService->updateVariant($variant, $validated);

            // Update or create variant-level pricing
            if (isset($validated['pricing'])) {
                $pricingData = $validated['pricing'];
                $existing = $variant->pricing;
                if ($existing) {
                    $existing->update($pricingData);
                    $existing->calculateMargin();
                    $existing->save();
                } else {
                    $pricingData['variant_id'] = $variant->id;
                    $pricingData['product_id'] = $variant->product_id;
                    $pricing = $variant->pricing()->create($pricingData);
                    $pricing->calculateMargin();
                    $pricing->save();
                }
            }

            // Update variant-level attributes if provided and non-empty.
            // Guard against empty array: calling assignVariantAttributes([]) would
            // delete all existing attributes and recreate nothing — data loss.
            if (isset($validated['attributes']) && !empty($validated['attributes'])) {
                $this->productService->assignVariantAttributes($variant, $validated['attributes']);
            }

            return response()->json([
                'message' => 'Variante actualizada correctamente',
                'data' => $variant->fresh(['brand', 'pricing', 'inventory', 'attributes'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified variant.
     */
    public function destroy(Product $product, ProductVariant $variant): JsonResponse
    {
        if ($variant->product_id !== $product->id) {
            return response()->json(['message' => 'Variante no corresponde al producto'], 404);
        }

        $variant->delete();

        return response()->json([
            'message' => 'Variante eliminada'
        ]);
    }
}
