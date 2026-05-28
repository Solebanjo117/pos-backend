<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPosSessionRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in(['open', 'closed'])],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'El estado de sesion seleccionado no es valido.',
            'warehouse_id.integer' => 'El identificador de sucursal debe ser un numero entero.',
            'warehouse_id.exists' => 'La sucursal indicada no existe.',
            'user_id.integer' => 'El identificador de usuario debe ser un numero entero.',
            'user_id.exists' => 'El usuario indicado no existe.',
            'date_from.date' => 'La fecha inicial debe ser valida.',
            'date_to.date' => 'La fecha final debe ser valida.',
            'date_to.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            'per_page.integer' => 'La cantidad por pagina debe ser un numero entero.',
            'per_page.min' => 'La cantidad por pagina debe ser al menos 1.',
            'per_page.max' => 'La cantidad por pagina no puede exceder 100.',
        ];
    }
}
