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
            'sku_base'       => 'nullable|string|max:50',
            'alternate_code' => 'nullable|string|max:100',
            'pricing_formula'=> 'nullable|string|max:255',
            'name'           => 'nullable|string|max:255',
            'description'    => 'nullable|string',
            'product_line_id' => 'nullable|exists:product_lines,id',
            'category_id'    => 'nullable|exists:categories,id',
            'engine_id'      => 'nullable|exists:vehicle_engines,id', // R21: motor as reusable entity
            'status'         => 'in:active,discontinued,pending',
            'attributes'     => 'nullable|array',
            'attributes.*.name'  => 'required_with:attributes|string|max:100',
            'attributes.*.value' => 'required_with:attributes|string|max:255',

            // Default Variant (opcional) — SKU and UPC are no longer unique keys
            'sku'      => 'nullable|string|max:50',
            'upc'      => 'nullable|string|max:255',
            'brand_id' => 'nullable|exists:brands,id',

            // Pricing (opcional, solo si se crea variante default)
            'pricing' => 'nullable|array',
            'pricing.base_price' => 'required_with:pricing|numeric|min:0',
            'pricing.msrp' => 'nullable|numeric|min:0',
            'pricing.cost' => 'nullable|numeric|min:0',
            'pricing.tax_rate' => 'required_with:pricing|numeric|between:0,15',
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
            'brand_id.exists'   => 'La marca seleccionada no existe',
            'pricing.base_price.required_with' => 'El precio base es obligatorio cuando se define el pricing',
            'pricing.base_price.min'      => 'El precio base debe ser mayor o igual a 0',
            'pricing.tax_rate.required_with'   => 'La tasa de impuesto es obligatoria cuando se define el pricing',
            'pricing.tax_rate.between'    => 'La tasa de impuesto debe estar entre 0 y 15%',
            'category_id.exists'          => 'La categoría seleccionada no existe',
            'status.in'                   => 'El estado debe ser: activo, discontinuado o pendiente',
            'initial_inventory.*.warehouse_id.required'    => 'El almacén es obligatorio',
            'initial_inventory.*.warehouse_id.exists'      => 'El almacén seleccionado no existe',
            'initial_inventory.*.stock_available.required' => 'El stock disponible es obligatorio',
            'initial_inventory.*.stock_available.min'      => 'El stock debe ser mayor o igual a 0',
            'vins.*.min' => 'El VIN debe tener al menos 1 carácter',
            'vins.*.max' => 'El VIN no puede tener más de 40 caracteres',
            'oem_references.*.min' => 'La referencia debe tener al menos 1 carácter',
            'oem_references.*.max' => 'La referencia no puede exceder 100 caracteres',
        ];
    }
}
