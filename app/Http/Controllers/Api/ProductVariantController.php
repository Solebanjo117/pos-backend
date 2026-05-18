<?php

namespace App\Http\Controllers\Api;

use App\Helpers\TaxRateHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductVariantFifoHistoryRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\InventoryFifoHistoryService;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductVariantController extends Controller
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly InventoryFifoHistoryService $fifoHistoryService,
    ) {}

    /**
     * Display a listing of variants for a product.
     */
    public function index(Product $product): JsonResponse
    {
        $variants = $product->variants()
            ->with(['brand', 'pricing', 'inventory.warehouse', 'attributes', 'suppliers'])
            ->get();

        return response()->json([
            'data' => $variants,
        ]);
    }

    /**
     * Store a newly created variant.
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        $this->normalizePricingTaxRates($request);

        $validated = $request->validate([
            'brand_id' => 'required|exists:brands,id',
            'sku' => [
                'nullable',
                'string',
                'max:255',
            ],
            'upc' => [
                'nullable',
                'string',
                'max:50',
            ],
            'status' => 'nullable|in:active,discontinued,pending',

            // Pricing (optional)
            'pricing' => 'nullable|array',
            'pricing.base_price' => 'required_with:pricing|numeric|min:0',
            'pricing.msrp' => 'nullable|numeric|min:0',
            'pricing.cost' => 'nullable|numeric|min:0',
            'pricing.cost_base' => 'nullable|numeric|min:0',
            'pricing.cost_miscellaneous' => 'nullable|numeric|min:0',
            'pricing.tax_rate' => 'nullable|integer|min:0|max:100',
            'pricing.cost_tax_rate' => 'nullable|integer|min:0|max:100',
            'pricing.sale_tax_rate' => 'nullable|integer|min:0|max:100',
            'pricing.max_discount_percentage' => 'nullable|numeric|min:0|max:100',
            'pricing.pricing_formula' => 'nullable|string|max:255',

            // Initial Inventory (optional)
            'initial_inventory' => 'nullable|array',
            'initial_inventory.*.warehouse_id' => 'required_with:initial_inventory|exists:warehouses,id',
            'initial_inventory.*.stock_available' => 'nullable|integer|min:0',
            'initial_inventory.*.reorder_point' => 'nullable|integer|min:0',
            'initial_inventory.*.reorder_quantity' => 'nullable|integer|min:0',

            'attributes' => 'missing',
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

            // Auto-generate SKU if not provided
            $sku = $validated['sku'];
            if (empty($sku)) {
                // Use product sku_base or name as base, append brand short name or ID
                $base = $product->sku_base ?: ($product->name ? substr($product->name, 0, 10) : 'PROD');
                $brand = \App\Models\Brand::find($validated['brand_id']);
                $brandCode = $brand ? substr($brand->name, 0, 3) : 'XXX';
                $sku = strtoupper($base.'-'.$brandCode.'-'.substr(uniqid(), -6));
            }

            $variant = $this->productService->createVariant($product, [
                'brand_id' => $validated['brand_id'],
                'sku' => $sku,
                'upc' => $validated['upc'] ?? null,
                'pricing' => $validated['pricing'] ?? null,
                'status' => $validated['status'] ?? 'active',
                'is_default' => false,
            ]);

            // Add Initial Inventory
            if (! empty($validated['initial_inventory'])) {
                foreach ($validated['initial_inventory'] as $invData) {
                    // Skip if warehouse_id is not valid
                    if (empty($invData['warehouse_id'])) {
                        continue;
                    }

                    $variant->inventory()->create([
                        'warehouse_id' => $invData['warehouse_id'],
                        'quantity_available' => $invData['stock_available'] ?? 0,
                        'reorder_point' => $invData['reorder_point'] ?? 0,
                        'reorder_quantity' => $invData['reorder_quantity'] ?? 0,
                        'product_id' => $product->id,
                    ]);
                }
            } else {
                // Try to create inventory for default warehouse if it exists
                $defaultWarehouse = \DB::table('warehouses')->first();
                if ($defaultWarehouse) {
                    $variant->inventory()->create([
                        'warehouse_id' => $defaultWarehouse->id,
                        'quantity_available' => 0,
                        'reorder_point' => 5,
                        'reorder_quantity' => 10,
                        'product_id' => $product->id,
                    ]);
                }
            }

            // Create variant-level pricing (always - either from request or copied from product)
            $pricingData = $validated['pricing'] ?? null;

            if (! $pricingData) {
                // Copy product's current pricing as independent variant pricing
                $productPricing = $product->pricing;
                if ($productPricing) {
                    $saleTaxRate = $productPricing->sale_tax_rate ?? $productPricing->tax_rate;
                    $costTaxRate = $productPricing->cost_tax_rate ?? $saleTaxRate;

                    $pricingData = [
                        'base_price' => $productPricing->base_price,
                        'msrp' => $productPricing->msrp,
                        'cost' => (float) $productPricing->getRawOriginal('cost'),
                        'cost_base' => $productPricing->getRawOriginal('cost_base'),
                        'cost_miscellaneous' => $productPricing->getRawOriginal('cost_miscellaneous'),
                        'tax_rate' => $saleTaxRate,
                        'sale_tax_rate' => $saleTaxRate,
                        'cost_tax_rate' => $costTaxRate,
                        'max_discount_percentage' => $productPricing->max_discount_percentage,
                    ];
                }
            }

            if ($pricingData) {
                $pricingData = $this->normalizePricingCostBreakdown($pricingData);
                $pricingData['variant_id'] = $variant->id;
                $pricingData['product_id'] = $product->id;
                $pricing = $variant->pricing()->create($pricingData);
                $pricing->calculateMargin();
                $pricing->save();
            }

            $variant->loadMissing('pricing');
            foreach ($variant->inventory()->get() as $inventory) {
                app(\App\Services\InventoryFifoService::class)->ensureOpeningLotForInventory(
                    $product->id,
                    $variant->id,
                    (int) $inventory->warehouse_id,
                    (int) $inventory->quantity_available,
                    (float) ($variant->pricing?->cost_total ?? 0)
                );
            }

            \DB::commit();

            return response()->json([
                'message' => 'Variante creada exitosamente',
                'data' => $variant->load(['brand', 'pricing', 'inventory']),
            ], 201);

        } catch (\Exception $e) {
            \DB::rollBack();

            return response()->json([
                'message' => 'Error al crear la variante: '.$e->getMessage(),
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

        $this->normalizePricingTaxRates($request);

        $validated = $request->validate([
            'brand_id' => 'sometimes|exists:brands,id',
            'sku' => [
                'nullable',
                'string',
                'max:255',
            ],
            'upc' => [
                'nullable',
                'string',
                'max:50',
            ],
            'status' => 'nullable|in:active,discontinued,pending',

            // Pricing (optional)
            'pricing' => 'nullable|array',
            'pricing.base_price' => 'required_with:pricing|numeric|min:0',
            'pricing.msrp' => 'nullable|numeric|min:0',
            'pricing.cost' => 'nullable|numeric|min:0',
            'pricing.cost_base' => 'nullable|numeric|min:0',
            'pricing.cost_miscellaneous' => 'nullable|numeric|min:0',
            'pricing.tax_rate' => 'nullable|integer|min:0|max:100',
            'pricing.cost_tax_rate' => 'nullable|integer|min:0|max:100',
            'pricing.sale_tax_rate' => 'nullable|integer|min:0|max:100',
            'pricing.max_discount_percentage' => 'nullable|numeric|min:0|max:100',
            'pricing.pricing_formula' => 'nullable|string|max:255',

            'attributes' => 'missing',
        ]);

        try {
            $this->productService->updateVariant($variant, $validated);

            // Update or create variant-level pricing
            if (isset($validated['pricing'])) {
                $pricingData = $this->normalizePricingCostBreakdown($validated['pricing']);
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

            return response()->json([
                'message' => 'Variante actualizada correctamente',
                'data' => $variant->fresh(['brand', 'pricing', 'inventory']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar: '.$e->getMessage(),
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
            'message' => 'Variante eliminada',
        ]);
    }

    public function fifoHistory(ProductVariantFifoHistoryRequest $request, Product $product, ProductVariant $variant): JsonResponse
    {
        if ($variant->product_id !== $product->id) {
            return response()->json(['message' => 'Variante no corresponde al producto'], 404);
        }

        $history = $this->fifoHistoryService->forVariant($product, $variant, $request->validated());

        return response()->json([
            'data' => [
                'summary' => $history['summary'],
                'lots' => $history['lots']->map(fn (InventoryLot $lot) => [
                    'id' => $lot->id,
                    'warehouse_id' => $lot->warehouse_id,
                    'warehouse' => $lot->warehouse ? [
                        'id' => $lot->warehouse->id,
                        'name' => $lot->warehouse->name,
                        'code' => $lot->warehouse->code,
                    ] : null,
                    'source_type' => $lot->source_type,
                    'source_id' => $lot->source_id,
                    'source_movement_id' => $lot->source_movement_id,
                    'batch_number' => $lot->batch_number,
                    'received_quantity' => (int) $lot->received_quantity,
                    'quantity_available' => (int) $lot->quantity_available,
                    'unit_cost_base' => round((float) $lot->unit_cost_base, 2),
                    'tax_per_unit' => round((float) $lot->tax_per_unit, 2),
                    'misc_per_unit' => round((float) $lot->misc_per_unit, 2),
                    'landed_unit_cost' => round((float) $lot->landed_unit_cost, 2),
                    'remaining_value' => round((int) $lot->quantity_available * (float) $lot->landed_unit_cost, 2),
                    'received_at' => $lot->received_at?->toIso8601String(),
                ])->values(),
                'purchase_movements' => $history['purchase_movements']->map(fn ($movement) => [
                    ...((new InventoryMovementResource($movement))->resolve($request)),
                    'fifo_lots' => $movement->sourceLots->map(fn (InventoryLot $lot) => [
                        'lot_id' => $lot->id,
                        'quantity' => (int) $lot->received_quantity,
                        'quantity_available' => (int) $lot->quantity_available,
                        'unit_cost_base' => round((float) $lot->unit_cost_base, 2),
                        'tax_per_unit' => round((float) $lot->tax_per_unit, 2),
                        'misc_per_unit' => round((float) $lot->misc_per_unit, 2),
                        'landed_unit_cost' => round((float) $lot->landed_unit_cost, 2),
                        'remaining_value' => round((int) $lot->quantity_available * (float) $lot->landed_unit_cost, 2),
                        'received_at' => $lot->received_at?->toIso8601String(),
                    ])->values(),
                ])->values(),
                'sale_movements' => InventoryMovementResource::collection($history['sale_movements'])->resolve($request),
            ],
        ]);
    }

    private function normalizePricingTaxRates(Request $request): void
    {
        $pricing = $request->input('pricing');

        if (! is_array($pricing)) {
            return;
        }

        $hasLegacyTaxRate = array_key_exists('tax_rate', $pricing);
        $hasSaleTaxRate = array_key_exists('sale_tax_rate', $pricing);
        $hasCostTaxRate = array_key_exists('cost_tax_rate', $pricing);

        $legacyTaxRate = $this->normalizePercentValue($pricing['tax_rate'] ?? null);
        $saleTaxRate = $this->normalizePercentValue($pricing['sale_tax_rate'] ?? null);
        $costTaxRate = $this->normalizePercentValue($pricing['cost_tax_rate'] ?? null);

        if ($request->isMethod('post') && ! $hasLegacyTaxRate && ! $hasSaleTaxRate) {
            $pricing['tax_rate'] = TaxRateHelper::defaultRate();
            $pricing['sale_tax_rate'] = TaxRateHelper::defaultRate();
            $legacyTaxRate = TaxRateHelper::defaultRate();
            $saleTaxRate = TaxRateHelper::defaultRate();
        }

        if ($hasLegacyTaxRate) {
            $pricing['tax_rate'] = $legacyTaxRate;

            if (! $hasSaleTaxRate) {
                $pricing['sale_tax_rate'] = $legacyTaxRate;
            }

            if (! $hasCostTaxRate) {
                $pricing['cost_tax_rate'] = $legacyTaxRate;
            }
        }

        if ($hasSaleTaxRate) {
            $pricing['sale_tax_rate'] = $saleTaxRate;
            $pricing['tax_rate'] = $saleTaxRate;
        }

        if ($hasCostTaxRate) {
            $pricing['cost_tax_rate'] = $costTaxRate;
        }

        if (! $hasCostTaxRate && array_key_exists('sale_tax_rate', $pricing)) {
            $pricing['cost_tax_rate'] = $pricing['sale_tax_rate'];
        }

        $pricing = $this->normalizePricingCostBreakdown($pricing);

        $request->merge(['pricing' => $pricing]);
    }

    private function normalizePricingCostBreakdown(array $pricing): array
    {
        $hasCostBase = array_key_exists('cost_base', $pricing);
        $hasLegacyCost = array_key_exists('cost', $pricing);
        $hasCostMiscellaneous = array_key_exists('cost_miscellaneous', $pricing);

        $costBase = $this->normalizeMoneyValue($pricing['cost_base'] ?? null);
        $legacyCost = $this->normalizeMoneyValue($pricing['cost'] ?? null);
        $costMiscellaneous = $this->normalizeMoneyValue($pricing['cost_miscellaneous'] ?? null);
        $costTaxRate = $this->normalizePercentValue($pricing['cost_tax_rate'] ?? null) ?? 0;

        if ($hasCostBase) {
            $pricing['cost_base'] = $costBase;
        }

        if ($hasCostMiscellaneous) {
            $pricing['cost_miscellaneous'] = $costMiscellaneous ?? 0;
        }

        $effectiveCostBase = $hasCostBase ? $pricing['cost_base'] : null;
        $effectiveCostMiscellaneous = $pricing['cost_miscellaneous'] ?? 0;

        if ($effectiveCostBase !== null && is_numeric($effectiveCostBase)) {
            $pricing['cost'] = $this->computeCostTotal(
                (float) $effectiveCostBase,
                $costTaxRate,
                (float) $effectiveCostMiscellaneous,
            );
        } elseif ($hasLegacyCost && $legacyCost !== null) {
            $pricing['cost'] = $legacyCost;
        }

        return $pricing;
    }

    private function normalizePercentValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    private function normalizeMoneyValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function computeCostTotal(float $costBase, int $costTaxRate, float $costMiscellaneous): float
    {
        return (float) round($costBase * (1 + ($costTaxRate / 100)) + $costMiscellaneous, 2);
    }
}
