<?php

namespace App\Http\Requests;

use App\Helpers\TaxRateHelper;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'brand_id' => 'required|exists:brands,id',
            'sku' => ['nullable', 'string', 'max:255'],
            'upc' => ['nullable', 'string', 'max:50'],
            'status' => 'nullable|in:active,discontinued,pending',

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

            'initial_inventory' => 'nullable|array',
            'initial_inventory.*.warehouse_id' => 'required_with:initial_inventory|exists:warehouses,id',
            'initial_inventory.*.stock_available' => 'nullable|integer|min:0',
            'initial_inventory.*.reorder_point' => 'nullable|integer|min:0',
            'initial_inventory.*.reorder_quantity' => 'nullable|integer|min:0',

            'attributes' => 'missing',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'brand_id.required' => 'La marca es obligatoria.',
            'brand_id.exists' => 'La marca seleccionada no existe.',
            'pricing.base_price.required_with' => 'El precio de venta es obligatorio cuando se envian precios.',
            'pricing.base_price.min' => 'El precio de venta debe ser mayor o igual a 0.',
            'initial_inventory.*.warehouse_id.required_with' => 'El almacen es obligatorio para el stock inicial.',
            'initial_inventory.*.warehouse_id.exists' => 'El almacen seleccionado no existe.',
            'initial_inventory.*.stock_available.min' => 'El stock inicial debe ser mayor o igual a 0.',
            'attributes.missing' => 'Los atributos no se actualizan desde este formulario.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $pricing = $this->input('pricing');

        if (! is_array($pricing)) {
            return;
        }

        $hasLegacyTaxRate = array_key_exists('tax_rate', $pricing);
        $hasSaleTaxRate = array_key_exists('sale_tax_rate', $pricing);
        $hasCostTaxRate = array_key_exists('cost_tax_rate', $pricing);

        $legacyTaxRate = $this->normalizePercentValue($pricing['tax_rate'] ?? null);
        $saleTaxRate = $this->normalizePercentValue($pricing['sale_tax_rate'] ?? null);
        $costTaxRate = $this->normalizePercentValue($pricing['cost_tax_rate'] ?? null);

        if (! $hasLegacyTaxRate && ! $hasSaleTaxRate) {
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

        $this->merge(['pricing' => $this->normalizePricingCostBreakdown($pricing)]);
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @return array<string, mixed>
     */
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
            $pricing['cost'] = (float) round((float) $effectiveCostBase * (1 + ($costTaxRate / 100)) + (float) $effectiveCostMiscellaneous, 2);
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
}
