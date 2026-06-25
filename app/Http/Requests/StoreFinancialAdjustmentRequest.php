<?php

namespace App\Http\Requests;

use App\Models\FinancialAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'type' => ['required', 'string', Rule::in(array_keys(FinancialAdjustment::TYPES))],
            'amount' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'max:255'],
            'adjustment_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'El tipo de ajuste es obligatorio.',
            'type.in' => 'El tipo de ajuste debe ser cash o inventory.',
            'amount.required' => 'El monto del ajuste es obligatorio.',
            'amount.numeric' => 'El monto debe ser numerico.',
            'reason.required' => 'El motivo del ajuste es obligatorio.',
            'reason.max' => 'El motivo no puede superar 255 caracteres.',
            'adjustment_date.required' => 'La fecha del ajuste es obligatoria.',
            'adjustment_date.date' => 'La fecha del ajuste no es valida.',
        ];
    }
}
