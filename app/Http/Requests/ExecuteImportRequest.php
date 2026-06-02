<?php

namespace App\Http\Requests;

use App\Models\ImportRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'mode' => ['nullable', 'string', Rule::in([
                ImportRun::MODE_CREATE_UPDATE,
                ImportRun::MODE_CREATE_ONLY,
                ImportRun::MODE_UPDATE_STOCK_PRICE,
            ])],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.required' => 'Debe enviar filas para importar.',
            'rows.array' => 'Las filas deben enviarse como una lista.',
            'rows.*.__row.required' => 'Cada fila debe incluir su numero de fila original.',
            'rows.*.__row.integer' => 'El numero de fila debe ser entero.',
            'mode.string' => 'El modo de importacion debe ser texto.',
            'mode.in' => 'El modo de importacion seleccionado no es valido.',
        ];
    }
}
