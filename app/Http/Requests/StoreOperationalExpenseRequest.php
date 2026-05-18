<?php

namespace App\Http\Requests;

use App\Models\OperationalExpense;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOperationalExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'expense_date' => ['required', 'date'],
            'category' => ['required', 'string', Rule::in(array_keys(OperationalExpense::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', Rule::in(OperationalExpense::PAYMENT_METHODS)],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'expense_date.required' => 'La fecha del gasto es obligatoria.',
            'expense_date.date' => 'La fecha del gasto no es valida.',
            'category.required' => 'La categoria del gasto es obligatoria.',
            'category.in' => 'La categoria del gasto no es valida.',
            'amount.required' => 'El monto del gasto es obligatorio.',
            'amount.min' => 'El monto del gasto debe ser mayor a cero.',
            'payment_method.in' => 'El metodo de pago no es valido.',
        ];
    }
}
