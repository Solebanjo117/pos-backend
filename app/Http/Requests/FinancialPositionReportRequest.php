<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinancialPositionReportRequest extends FormRequest
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
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'date_to' => ['nullable', 'date'],
            'mode' => ['nullable', 'in:current_snapshot,as_of'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_id.exists' => 'La sucursal seleccionada no existe.',
            'date_to.date' => 'La fecha de corte debe ser una fecha valida.',
            'mode.in' => 'El modo debe ser foto actual o corte historico.',
        ];
    }
}
