<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertSupplierVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $updating = (bool) $this->route('variantId');

        return [
            'variant_id' => [$updating ? 'nullable' : 'required_without:product_id', 'nullable', 'exists:product_variants,id'],
            'product_id' => [$updating ? 'nullable' : 'required_without:variant_id', 'nullable', 'exists:products,id'],
            'supplier_cost' => 'required|numeric|min:0',
            'lead_time_days' => 'nullable|integer|min:0',
            'minimum_order_quantity' => 'nullable|integer|min:1',
            'is_preferred' => 'nullable|boolean',
        ];
    }
}
