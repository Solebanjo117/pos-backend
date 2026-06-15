<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RevalidateProductImportRunRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['sometimes', 'string', Rule::in(['full', 'references'])],
            'brands' => ['nullable', 'array'],
            'brands.*' => ['nullable', 'string', 'max:255'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'scope.string' => 'El alcance de revalidacion debe ser texto.',
            'scope.in' => 'El alcance de revalidacion debe ser full o references.',
            'brands.array' => 'Las marcas deben enviarse como una lista.',
            'brands.*.string' => 'Cada marca debe ser texto.',
            'brands.*.max' => 'Cada marca no puede superar 255 caracteres.',
            'categories.array' => 'Las categorias deben enviarse como una lista.',
            'categories.*.string' => 'Cada categoria debe ser texto.',
            'categories.*.max' => 'Cada categoria no puede superar 255 caracteres.',
        ];
    }
}
