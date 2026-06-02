<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductImportRunMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows_revision' => ['required', 'integer', 'min:0'],
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:100'],
            'visible_row_ids' => ['nullable', 'array', 'max:200'],
            'visible_row_ids.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows_revision.required' => 'Debe indicar la revision de filas del mapping.',
            'rows_revision.integer' => 'La revision de filas debe ser un numero entero.',
            'mapping.required' => 'Debe enviar el mapping actualizado.',
            'mapping.array' => 'El mapping actualizado no tiene un formato valido.',
            'mapping.*.string' => 'Cada destino del mapping debe ser texto.',
            'mapping.*.max' => 'Cada destino del mapping debe tener 100 caracteres o menos.',
            'visible_row_ids.array' => 'Las filas visibles no tienen un formato valido.',
            'visible_row_ids.max' => 'Solo se pueden remapear rapidamente hasta 200 filas visibles.',
            'visible_row_ids.*.integer' => 'Cada fila visible debe ser un identificador valido.',
        ];
    }
}
