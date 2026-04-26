<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'array'],
            'rows.*.__row' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.required' => 'Debe enviar filas para importar.',
            'rows.array' => 'Las filas deben enviarse como una lista.',
            'rows.*.__row.required' => 'Cada fila debe incluir su numero de fila original.',
            'rows.*.__row.integer' => 'El numero de fila debe ser entero.',
        ];
    }
}
