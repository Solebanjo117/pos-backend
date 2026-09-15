<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize canonical boolean query-string values before validation.
     */
    protected function prepareForValidation(): void
    {
        foreach (['in_stock', 'has_stock'] as $field) {
            $value = $this->input($field);

            if (! is_string($value)) {
                continue;
            }

            $normalizedValue = strtolower(trim($value));

            if ($normalizedValue === 'true' || $normalizedValue === 'false') {
                $this->merge([
                    $field => $normalizedValue === 'true',
                ]);
            }
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'in_stock' => ['nullable', 'boolean'],
            'has_stock' => ['nullable', 'boolean'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'sort' => ['nullable', 'in:name_asc,price_asc,price_desc,sku_asc,newest'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'in_stock.boolean' => 'El filtro de existencias debe ser verdadero o falso.',
            'has_stock.boolean' => 'El filtro de existencias debe ser verdadero o falso.',
            'max_price.gte' => 'El precio maximo debe ser mayor o igual al precio minimo.',
            'sort.in' => 'El orden seleccionado no es valido.',
        ];
    }
}
