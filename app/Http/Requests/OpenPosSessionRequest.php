<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenPosSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'opening_amount' => ['required', 'numeric', 'min:0'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'opening_amount.required' => 'El monto de apertura es obligatorio.',
            'opening_amount.numeric' => 'El monto de apertura debe ser numérico.',
            'opening_amount.min' => 'El monto de apertura no puede ser negativo.',
            'warehouse_id.required' => 'Se requiere una sucursal efectiva para abrir la sesión.',
            'warehouse_id.integer' => 'El identificador de sucursal debe ser un número entero.',
            'warehouse_id.exists' => 'La sucursal indicada no existe.',
        ];
    }
}
