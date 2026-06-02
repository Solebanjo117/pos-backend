<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BatchProductImportReferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'categories' => ['nullable', 'array'],
            'categories.*' => ['nullable', 'string', 'max:255'],
            'brands' => ['nullable', 'array'],
            'brands.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'categories.array' => 'Las categorias deben enviarse como una lista.',
            'categories.*.string' => 'Cada categoria debe ser texto.',
            'categories.*.max' => 'Cada categoria no puede superar 255 caracteres.',
            'brands.array' => 'Las marcas deben enviarse como una lista.',
            'brands.*.string' => 'Cada marca debe ser texto.',
            'brands.*.max' => 'Cada marca no puede superar 255 caracteres.',
        ];
    }
}
