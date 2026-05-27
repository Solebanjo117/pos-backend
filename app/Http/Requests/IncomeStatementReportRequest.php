<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IncomeStatementReportRequest extends FormRequest
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
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'basis' => ['nullable', 'in:cash,accrual_operational'],
            'compare' => ['nullable', 'in:previous_period,none'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_from.required' => 'La fecha inicial es obligatoria.',
            'date_from.date' => 'La fecha inicial debe ser una fecha valida.',
            'date_to.required' => 'La fecha final es obligatoria.',
            'date_to.date' => 'La fecha final debe ser una fecha valida.',
            'date_to.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            'warehouse_id.exists' => 'La sucursal seleccionada no existe.',
            'basis.in' => 'La base del reporte debe ser caja o devengo operativo.',
            'compare.in' => 'El comparativo debe ser periodo anterior o ninguno.',
        ];
    }
}
