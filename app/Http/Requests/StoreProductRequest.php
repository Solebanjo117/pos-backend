<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $pricing = $this->input('pricing');

        if (! is_array($pricing)) {
            return;
        }

        $legacyTaxRate = $this->normalizePercentValue($pricing['tax_rate'] ?? null);
        $saleTaxRate = $this->normalizePercentValue($pricing['sale_tax_rate'] ?? null);
        $costTaxRate = $this->normalizePercentValue($pricing['cost_tax_rate'] ?? null);

        $effectiveSaleTaxRate = $saleTaxRate ?? $legacyTaxRate;
        $effectiveCostTaxRate = $costTaxRate ?? $legacyTaxRate ?? $effectiveSaleTaxRate;

        if ($effectiveSaleTaxRate !== null) {
            $pricing['sale_tax_rate'] = $effectiveSaleTaxRate;
            // tax_rate remains as compatibility alias.
            $pricing['tax_rate'] = $effectiveSaleTaxRate;
        }

        if ($effectiveCostTaxRate !== null) {
            $pricing['cost_tax_rate'] = $effectiveCostTaxRate;
        }

        $pricing = $this->normalizePricingCostBreakdown($pricing);

        $this->merge(['pricing' => $pricing]);
    }

    private function normalizePercentValue($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    private function normalizeMoneyValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
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
            $pricing['cost'] = (float) round(
                ((float) $effectiveCostBase * (1 + ($costTaxRate / 100))) + (float) $effectiveCostMiscellaneous,
                2,
            );
        } elseif ($hasLegacyCost && $legacyCost !== null) {
            $pricing['cost'] = $legacyCost;
        }

        return $pricing;
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Implement based on your auth logic
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // Base Product
            'sku_base' => 'nullable|string|max:50',
            'alternate_code' => 'nullable|string|max:100',
            'pricing_formula' => 'nullable|string|max:255',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'product_line_id' => 'nullable|exists:product_lines,id',
            'category_id' => 'nullable|exists:categories,id',
            'extra_category_ids' => 'nullable|array',
            'extra_category_ids.*' => 'integer|distinct|exists:categories,id',
            'engine_id' => 'nullable|exists:vehicle_engines,id', // R21: motor as reusable entity
            'status' => 'in:active,discontinued,pending',
            'attributes' => 'nullable|array',
            'attributes.*.name' => 'required_with:attributes|string|max:100',
            'attributes.*.value' => 'required_with:attributes|string|max:255',
            'attributes.*.unit' => 'nullable|string|max:50',

            // Default Variant (opcional) — SKU and UPC are no longer unique keys
            'sku' => 'nullable|string|max:50',
            'upc' => 'nullable|string|max:255',
            'brand_id' => 'nullable|exists:brands,id',

            // Pricing (opcional, solo si se crea variante default)
            'pricing' => 'nullable|array',
            'pricing.base_price' => 'required_with:pricing|numeric|min:0',
            'pricing.msrp' => 'nullable|numeric|min:0',
            'pricing.cost' => 'nullable|numeric|min:0',
            'pricing.cost_base' => 'nullable|numeric|min:0',
            'pricing.cost_miscellaneous' => 'nullable|numeric|min:0',
            'pricing.tax_rate' => 'nullable|integer|between:0,100',
            'pricing.cost_tax_rate' => 'nullable|integer|between:0,100',
            'pricing.sale_tax_rate' => 'nullable|integer|between:0,100',
            'pricing.tax_type' => 'sometimes|in:standard,exempt,custom',
            'pricing.max_discount_percentage' => 'nullable|numeric|between:0,100',

            // Initial Inventory (optional)
            'initial_inventory' => 'nullable|array',
            'initial_inventory.*.warehouse_id' => 'required|exists:warehouses,id',
            'initial_inventory.*.stock_available' => 'required|integer|min:0',
            'initial_inventory.*.reorder_point' => 'nullable|integer|min:0',
            'initial_inventory.*.target_level' => 'nullable|integer|min:0',

            // Vehicle Compatibility
            'vehicle_compatibility' => 'nullable|array',
            'vehicle_compatibility.*' => 'integer|exists:vehicle_engines,id',
            'is_universal' => 'nullable|boolean',

            // VINs
            'vins' => 'nullable|array',
            'vins.*' => 'string|min:1|max:40',

            // OEM / Cross References
            'oem_references' => 'nullable|array',
            'oem_references.*' => 'string|min:1|max:100',
        ];
    }

    /**
     * Custom error messages.
     */
    public function messages(): array
    {
        return [
            'brand_id.exists' => 'La marca seleccionada no existe',
            'pricing.base_price.required_with' => 'El precio base es obligatorio cuando se define el pricing',
            'pricing.base_price.min' => 'El precio base debe ser mayor o igual a 0',
            'pricing.tax_rate.between' => 'La tasa de impuesto de venta debe estar entre 0 y 100%',
            'pricing.sale_tax_rate.between' => 'La tasa de impuesto de venta debe estar entre 0 y 100%',
            'pricing.cost_tax_rate.between' => 'La tasa de impuesto de costo debe estar entre 0 y 100%',
            'category_id.exists' => 'La categoría seleccionada no existe',
            'extra_category_ids.*.exists' => 'Una de las categorÃ­as extra seleccionadas no existe',
            'status.in' => 'El estado debe ser: activo, discontinuado o pendiente',
            'initial_inventory.*.warehouse_id.required' => 'El almacén es obligatorio',
            'initial_inventory.*.warehouse_id.exists' => 'El almacén seleccionado no existe',
            'initial_inventory.*.stock_available.required' => 'El stock disponible es obligatorio',
            'initial_inventory.*.stock_available.min' => 'El stock debe ser mayor o igual a 0',
            'vins.*.min' => 'El VIN debe tener al menos 1 carácter',
            'vins.*.max' => 'El VIN no puede tener más de 40 caracteres',
            'oem_references.*.min' => 'La referencia debe tener al menos 1 carácter',
            'oem_references.*.max' => 'La referencia no puede exceder 100 caracteres',
        ];
    }
}
