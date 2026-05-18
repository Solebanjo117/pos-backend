<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
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
            // tax_rate remains as compatibility alias.
            $pricing['tax_rate'] = $saleTaxRate;
        }

        if ($hasCostTaxRate) {
            $pricing['cost_tax_rate'] = $costTaxRate;
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
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'sku_base' => 'nullable|string|max:50',
            'alternate_code' => 'nullable|string|max:100',
            'pricing_formula' => 'nullable|string|max:255',
            'upc' => 'nullable|string|max:255',
            'product_line_id' => 'nullable|exists:product_lines,id',
            'category_id' => 'nullable|exists:categories,id',
            'extra_category_ids' => 'sometimes|array',
            'extra_category_ids.*' => 'integer|distinct|exists:categories,id',
            'engine_id' => 'nullable|exists:vehicle_engines,id', // R21: motor as reusable entity
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:active,discontinued,pending',
            'is_universal' => 'sometimes|boolean',
            'attributes' => 'sometimes|array',
            'attributes.*.name' => 'required_with:attributes|string|max:100',
            'attributes.*.value' => ['required_with:attributes', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value)) {
                    if (mb_strlen($value) > 1000) {
                        $fail('El valor del atributo no puede exceder 1000 caracteres.');
                    }

                    return;
                }
                if (is_array($value)) {
                    foreach ($value as $item) {
                        if (! is_string($item) || mb_strlen($item) > 255) {
                            $fail('Cada opción seleccionada debe ser texto de máximo 255 caracteres.');

                            return;
                        }
                    }

                    return;
                }
                $fail('El valor del atributo debe ser texto o una lista de opciones.');
            }],
            'attributes.*.unit' => 'nullable|string|max:50',
            'attributes.*.source' => 'nullable|string|in:custom,category',
            'attributes.*.source_category_id' => 'nullable|integer|exists:categories,id',
            'oem_references' => 'sometimes|array',
            'oem_references.*.oem_code' => 'required_with:oem_references|string|max:50',
            'oem_references.*.manufacturer' => 'nullable|string|max:100',
            'alternatives' => 'sometimes|array',
            'alternatives.*.id' => 'nullable|integer|exists:products,id',
            'alternatives.*.alternative_product_id' => 'nullable|integer|exists:products,id',
            'alternatives.*.relationship_type' => 'nullable|in:compatible,substitute,upgrade',
            'vehicle_compatibility' => 'sometimes|array',
            'vehicle_compatibility.engines' => 'sometimes|array',
            'vehicle_compatibility.engines.*.engine_id' => 'required_with:vehicle_compatibility.engines|integer|exists:vehicle_engines,id',
            'vehicle_compatibility.chassis' => 'sometimes|array',
            'vehicle_compatibility.chassis.*.chassis_id' => 'required_with:vehicle_compatibility.chassis|integer|exists:vehicle_chassis,id',
            'vehicle_compatibility.makes' => 'sometimes|array',
            'vehicle_compatibility.makes.*.make_id' => 'required_with:vehicle_compatibility.makes|integer|exists:vehicle_makes,id',
            'vehicle_compatibility.models' => 'sometimes|array',
            'vehicle_compatibility.models.*.model_id' => 'required_with:vehicle_compatibility.models|integer|exists:vehicle_models,id',
            'vehicle_compatibility.allEngines' => 'sometimes|boolean',
            'vehicle_compatibility.allChassis' => 'sometimes|boolean',
            'vehicle_compatibility.isUniversal' => 'sometimes|boolean',
            'vehicle_compatibility.all_engines' => 'sometimes|boolean',
            'vehicle_compatibility.all_chassis' => 'sometimes|boolean',
            'vehicle_compatibility.model_id' => 'sometimes|integer|exists:vehicle_models,id',

            // Pricing (all optional for update)
            'pricing' => 'sometimes|array',
            'pricing.base_price' => 'sometimes|numeric|min:0',
            'pricing.msrp' => 'nullable|numeric|min:0',
            'pricing.cost' => 'nullable|numeric|min:0',
            'pricing.cost_base' => 'sometimes|nullable|numeric|min:0',
            'pricing.cost_miscellaneous' => 'sometimes|nullable|numeric|min:0',
            'pricing.tax_rate' => 'sometimes|nullable|integer|between:0,100',
            'pricing.cost_tax_rate' => 'sometimes|nullable|integer|between:0,100',
            'pricing.sale_tax_rate' => 'sometimes|nullable|integer|between:0,100',
            'pricing.tax_type' => 'sometimes|in:standard,exempt,custom',
            'pricing.max_discount_percentage' => 'nullable|numeric|between:0,100',
        ];
    }

    /**
     * Custom error messages.
     */
    public function messages(): array
    {
        return [
            'sku_base.unique' => 'El SKU ya está en uso por otro producto',
            'extra_category_ids.*.exists' => 'Una de las categorÃ­as extra seleccionadas no existe',
            'status.in' => 'El estado debe ser: active, discontinued o pending',
        ];
    }
}
