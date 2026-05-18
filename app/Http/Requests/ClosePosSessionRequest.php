<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClosePosSessionRequest extends FormRequest
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
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'closing_amount' => ['required', 'numeric', 'min:0'],
            'closing_details' => ['nullable', 'array'],
            'closing_details.cash_denominations' => ['nullable', 'array'],
            'closing_details.cash_denominations.*' => ['nullable', 'numeric', 'min:0'],
            'closing_details.cash_extra' => ['nullable', 'numeric', 'min:0'],
            'closing_details.payment_method_counts' => ['nullable', 'array'],
            'closing_details.payment_method_counts.*' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_id.required' => 'Se requiere una sucursal efectiva para cerrar la sesión.',
            'warehouse_id.integer' => 'El identificador de sucursal debe ser un número entero.',
            'warehouse_id.exists' => 'La sucursal indicada no existe.',
            'closing_amount.required' => 'El monto de cierre es obligatorio.',
            'closing_amount.numeric' => 'El monto de cierre debe ser numérico.',
            'closing_amount.min' => 'El monto de cierre no puede ser negativo.',
            'closing_details.array' => 'El detalle del cierre debe tener un formato válido.',
            'notes.max' => 'La nota no puede superar 1000 caracteres.',
        ];
    }
}
