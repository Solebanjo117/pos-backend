<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProductCodeConflictException;
use App\Helpers\TaxRateHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductVariantFifoHistoryRequest;
use App\Http\Requests\StoreProductVariantRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\ProductVariantResource;
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
            ->with(['brand', 'codes', 'pricing', 'inventory.warehouse', 'attributes', 'suppliers'])
            ->get();

        return response()->json([
            'data' => ProductVariantResource::collection($variants)->resolve(),
        ]);
    }

    /**
     * Store a newly created variant.
     */
    public function store(StoreProductVariantRequest $request, Product $product): JsonResponse
    {
        try {
            $variant = $this->productService->createVariantWithInitialInventory(
                $product,
                $request->validated()
            );

            return response()->json([
                'message' => 'Variante creada exitosamente',
                'data' => (new ProductVariantResource($variant))->resolve($request),
            ], 201);

        } catch (ProductCodeConflictException $exception) {
            throw $exception;
        } catch (\Exception $e) {
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
            'codes' => 'sometimes|array|max:25',
            'codes.*.id' => 'nullable|integer',
            'codes.*.code' => 'required|string|max:255',
            'codes.*.type' => 'required|in:sku,upc,alternate,manual',
            'codes.*.is_primary' => 'required|boolean',

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
                'data' => (new ProductVariantResource($variant->fresh(['brand', 'codes', 'pricing', 'inventory'])))->resolve($request),
            ]);
        } catch (ProductCodeConflictException $exception) {
            throw $exception;
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

    public function inventoryHistory(ProductVariantFifoHistoryRequest $request, Product $product, ProductVariant $variant): JsonResponse
    {
        if ($variant->product_id !== $product->id) {
            return response()->json(['message' => 'Variante no corresponde al producto'], 404);
        }

        $history = $this->fifoHistoryService->forVariant($product, $variant, $request->validated());

        return response()->json([
            'data' => [
                'summary' => $history['summary'],
                'lots' => $history['lots']->map(fn (InventoryLot $lot) => $this->serializedHistoryLot($lot))->values(),
                'movement_timeline' => InventoryMovementResource::collection($history['movement_timeline'])->resolve($request),
                'purchase_movements' => $history['purchase_movements']->map(fn ($movement) => [
                    ...((new InventoryMovementResource($movement))->resolve($request)),
                    'source_lots' => $movement->sourceLots->map(fn (InventoryLot $lot) => $this->serializedMovementSourceLot($lot))->values(),
                    'fifo_lots' => $movement->sourceLots->map(fn (InventoryLot $lot) => $this->serializedMovementSourceLot($lot))->values(),
                ])->values(),
                'sale_movements' => InventoryMovementResource::collection($history['sale_movements'])->resolve($request),
            ],
        ]);
    }

    public function fifoHistory(ProductVariantFifoHistoryRequest $request, Product $product, ProductVariant $variant): JsonResponse
    {
        return $this->inventoryHistory($request, $product, $variant);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializedHistoryLot(InventoryLot $lot): array
    {
        return [
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
            'tax_total' => round((int) $lot->received_quantity * (float) $lot->tax_per_unit, 2),
            'misc_per_unit' => round((float) $lot->misc_per_unit, 2),
            'misc_total' => round((int) $lot->received_quantity * (float) $lot->misc_per_unit, 2),
            'landed_unit_cost' => round((float) $lot->landed_unit_cost, 2),
            'landed_total' => round((int) $lot->received_quantity * (float) $lot->landed_unit_cost, 2),
            'sale_price' => round((float) $lot->sale_price, 2),
            'remaining_value' => round((int) $lot->quantity_available * (float) $lot->landed_unit_cost, 2),
            'received_at' => $lot->received_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializedMovementSourceLot(InventoryLot $lot): array
    {
        return [
            'lot_id' => $lot->id,
            'quantity' => (int) $lot->received_quantity,
            'quantity_available' => (int) $lot->quantity_available,
            'unit_cost_base' => round((float) $lot->unit_cost_base, 2),
            'tax_per_unit' => round((float) $lot->tax_per_unit, 2),
            'tax_total' => round((int) $lot->received_quantity * (float) $lot->tax_per_unit, 2),
            'misc_per_unit' => round((float) $lot->misc_per_unit, 2),
            'misc_total' => round((int) $lot->received_quantity * (float) $lot->misc_per_unit, 2),
            'landed_unit_cost' => round((float) $lot->landed_unit_cost, 2),
            'landed_total' => round((int) $lot->received_quantity * (float) $lot->landed_unit_cost, 2),
            'sale_price' => round((float) $lot->sale_price, 2),
            'remaining_value' => round((int) $lot->quantity_available * (float) $lot->landed_unit_cost, 2),
            'received_at' => $lot->received_at?->toIso8601String(),
        ];
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
