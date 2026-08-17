<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CheckProductCodeRequest extends FormRequest
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
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required_without:sku', 'nullable', 'string', 'max:255'],
            'sku' => ['required_without:code', 'nullable', 'string', 'max:255'],
            'exclude_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'exclude_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
        ];
    }
}
