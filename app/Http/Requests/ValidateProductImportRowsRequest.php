<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValidateProductImportRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1'],
            'rows.*' => ['required', 'array'],
            'rows.*.__row' => ['required', 'integer'],
            'mode' => ['nullable', 'string', 'max:50'],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:100'],
            'sheet_index' => ['nullable', 'integer', 'min:0'],
            'profile_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.required' => 'Debe enviar filas para validar.',
            'rows.array' => 'Las filas deben enviarse como una lista.',
            'rows.min' => 'Debe enviar al menos una fila para validar.',
            'rows.*.array' => 'Cada fila debe enviarse como un objeto.',
            'rows.*.__row.required' => 'Cada fila debe incluir su numero de fila original.',
            'rows.*.__row.integer' => 'El numero de fila debe ser entero.',
            'mode.string' => 'El modo de importacion debe ser texto.',
            'mode.max' => 'El modo de importacion no puede superar 50 caracteres.',
            'mapping.array' => 'El mapeo debe enviarse como un objeto.',
            'mapping.*.string' => 'Cada columna del mapeo debe ser texto.',
            'mapping.*.max' => 'Cada columna del mapeo no puede superar 100 caracteres.',
            'sheet_index.integer' => 'La hoja debe ser un numero entero.',
            'sheet_index.min' => 'La hoja no puede ser negativa.',
            'profile_id.integer' => 'El perfil seleccionado no es valido.',
        ];
    }
}
