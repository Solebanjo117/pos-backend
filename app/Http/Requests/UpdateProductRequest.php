<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;


class UpdateProductRequest extends FormRequest
{
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
        $productId = $this->route('product');

        return [
            'sku_base'       => 'nullable|string|max:50',
            'alternate_code' => 'nullable|string|max:100',
            'pricing_formula'=> 'nullable|string|max:255',
            'upc'            => 'nullable|string|max:255',
            'product_line_id' => 'nullable|exists:product_lines,id',
            'category_id'    => 'nullable|exists:categories,id',
            'engine_id'      => 'nullable|exists:vehicle_engines,id', // R21: motor as reusable entity
            'name'           => 'sometimes|string|max:255',
            'description'    => 'nullable|string',
            'status'         => 'sometimes|in:active,discontinued,pending',
            'is_universal'   => 'sometimes|boolean',
            'attributes'     => 'sometimes|array',
            'attributes.*.name'  => 'required_with:attributes|string|max:100',
            'attributes.*.value' => 'required_with:attributes|string|max:255',
            
            // Pricing (all optional for update)
            'pricing'                           => 'sometimes|array',
            'pricing.base_price'                => 'sometimes|numeric|min:0',
            'pricing.msrp'                      => 'nullable|numeric|min:0',
            'pricing.cost'                      => 'nullable|numeric|min:0',
            'pricing.tax_rate'                  => 'sometimes|numeric|between:0,15',
            'pricing.tax_type'                  => 'sometimes|in:standard,exempt,custom',
            'pricing.max_discount_percentage'   => 'nullable|numeric|between:0,100',
        ];
    }

    /**
     * Custom error messages.
     */
    public function messages(): array
    {
        return [
            'sku_base.unique' => 'El SKU ya está en uso por otro producto',
            'status.in' => 'El estado debe ser: active, discontinued o pending',
        ];
    }
}
