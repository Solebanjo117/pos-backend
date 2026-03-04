<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
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
            'sku_base' => 'nullable|string|max:50|unique:products,sku_base',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'product_line_id' => 'nullable|exists:product_lines,id',
            'category_id' => 'nullable|exists:categories,id',
            'status' => 'in:active,discontinued,pending',
            'attributes' => 'nullable|array',
            'attributes.*.name' => 'required_with:attributes|string|max:100',
            'attributes.*.value' => 'required_with:attributes|string|max:255',

            // Default Variant
            'sku' => 'nullable|string|max:50|unique:product_variants,sku',
            'upc' => 'nullable|string|max:20|unique:product_variants,upc',
            'brand_id' => 'required|exists:brands,id',
            
            // Pricing
            'pricing' => 'required|array',
            'pricing.base_price' => 'required|numeric|min:0',
            'pricing.msrp' => 'nullable|numeric|min:0',
            'pricing.cost' => 'nullable|numeric|min:0',
            'pricing.tax_rate' => 'required|numeric|between:0,15',
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

            // VINs
            'vins' => 'nullable|array',
            'vins.*' => 'string|min:1|max:40',
        ];
    }

    /**
     * Custom error messages.
     */
    public function messages(): array
    {
        return [
            'sku.unique' => 'El SKU de la variante ya está registrado',
            'sku_base.unique' => 'El SKU base del producto ya existe',
            'upc.unique' => 'El código UPC ya está registrado',
            'pricing.msrp.gte' => 'El MSRP debe ser mayor o igual al precio base',
            'brand_id.exists' => 'La marca seleccionada no existe',
        ];
    }
}
