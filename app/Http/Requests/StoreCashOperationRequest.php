<?php

namespace App\Http\Requests;

use App\Models\CashOperationRequest as CashOperation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashOperationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user?->hasRole(['admin', 'manager', 'cashier'])) {
            return false;
        }

        return ! in_array($this->input('operation_type'), [CashOperation::TYPE_EXPENSE, CashOperation::TYPE_FUNDING], true)
            || $user->hasRole(['admin', 'manager']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = (string) $this->input('operation_type');

        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'operation_type' => ['required', 'string', Rule::in([
                CashOperation::TYPE_CASH_IN,
                CashOperation::TYPE_CASH_OUT,
                CashOperation::TYPE_EXPENSE,
                CashOperation::TYPE_FUNDING,
            ])],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'expense_category_id' => [
                Rule::requiredIf($type === CashOperation::TYPE_EXPENSE),
                'nullable', 'integer',
                Rule::exists('operational_expense_categories', 'id')->where('is_active', true),
            ],
            'funding_category_id' => [
                Rule::requiredIf($type === CashOperation::TYPE_FUNDING),
                'nullable', 'integer',
                Rule::exists('funding_categories', 'id')->where('is_active', true),
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'El monto es obligatorio.',
            'amount.decimal' => 'El monto debe tener un máximo de dos decimales.',
            'amount.min' => 'El monto debe ser mayor a cero.',
            'expense_category_id.required' => 'La categoría del gasto es obligatoria.',
            'funding_category_id.required' => 'La categoría de la aportación es obligatoria.',
        ];
    }
}
