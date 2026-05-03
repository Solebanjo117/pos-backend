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
        return [
            'variant_id' => ($this->route('variantId') ? 'nullable' : 'required').'|exists:product_variants,id',
            'supplier_cost' => 'required|numeric|min:0',
            'lead_time_days' => 'nullable|integer|min:0',
            'minimum_order_quantity' => 'nullable|integer|min:1',
            'is_preferred' => 'nullable|boolean',
        ];
    }
}
