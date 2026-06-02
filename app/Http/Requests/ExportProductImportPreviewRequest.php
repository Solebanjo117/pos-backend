<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportProductImportPreviewRequest extends FormRequest
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
            'rows.*.__row' => ['nullable', 'integer'],
            'rows.*.__valid' => ['nullable', 'boolean'],
            'rows.*.__action_type' => ['nullable', 'string', 'max:50'],
            'rows.*.__errors' => ['nullable', 'array'],
            'rows.*.__errors.*' => ['nullable', 'string', 'max:500'],
            'rows.*.nombre' => ['nullable', 'string'],
            'rows.*.sku' => ['nullable', 'string'],
            'rows.*.system_id' => ['nullable', 'string'],
            'rows.*.brand' => ['nullable', 'string'],
            'rows.*.categoria' => ['nullable', 'string'],
            'rows.*.costo' => ['nullable'],
            'rows.*.precio' => ['nullable'],
            'rows.*.cantidad' => ['nullable'],
            'rows.*.imagen_url' => ['nullable', 'string'],
            'rows.*.descripcion' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.required' => 'Debe enviar filas para exportar.',
            'rows.array' => 'Las filas deben enviarse como una lista.',
            'rows.min' => 'Debe enviar filas para exportar.',
            'rows.*.array' => 'Cada fila debe enviarse como un objeto.',
            'rows.*.__row.integer' => 'El numero de fila debe ser entero.',
            'rows.*.__valid.boolean' => 'El estado de validez de cada fila debe ser verdadero o falso.',
            'rows.*.__action_type.string' => 'La accion prevista de cada fila debe ser texto.',
            'rows.*.__action_type.max' => 'La accion prevista no puede superar 50 caracteres.',
            'rows.*.__errors.array' => 'Los errores de cada fila deben enviarse como una lista.',
            'rows.*.__errors.*.string' => 'Cada error debe ser texto.',
            'rows.*.__errors.*.max' => 'Cada error no puede superar 500 caracteres.',
        ];
    }
}
