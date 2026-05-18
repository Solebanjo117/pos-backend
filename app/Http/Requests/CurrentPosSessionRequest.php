<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CurrentPosSessionRequest extends FormRequest
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
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_id.required' => 'Se requiere una sucursal efectiva para consultar la sesión actual.',
            'warehouse_id.integer' => 'El identificador de sucursal debe ser un número entero.',
            'warehouse_id.exists' => 'La sucursal indicada no existe.',
        ];
    }
}
