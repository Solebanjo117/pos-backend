<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductImportRunRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows_revision' => ['required', 'integer', 'min:0'],
            'rows' => ['required', 'array', 'min:1', 'max:200'],
            'rows.*.id' => ['required', 'integer'],
            'rows.*.updated_at' => ['required', 'date'],
            'rows.*.data' => ['required', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows_revision.required' => 'Debe indicar la revision de filas que esta editando.',
            'rows.required' => 'Debe enviar filas para guardar.',
            'rows.array' => 'Las filas deben enviarse como una lista.',
            'rows.min' => 'Debe enviar al menos una fila para guardar.',
            'rows.max' => 'Solo puede guardar hasta 200 filas por operacion.',
            'rows.*.id.required' => 'Cada fila debe incluir su identificador.',
            'rows.*.id.integer' => 'El identificador de fila debe ser un numero entero.',
            'rows.*.updated_at.required' => 'Cada fila debe incluir su fecha de actualizacion.',
            'rows.*.updated_at.date' => 'La fecha de actualizacion de cada fila no es valida.',
            'rows.*.data.required' => 'Cada fila debe incluir los datos corregidos.',
            'rows.*.data.array' => 'Los datos corregidos de cada fila deben enviarse como un objeto.',
        ];
    }
}
