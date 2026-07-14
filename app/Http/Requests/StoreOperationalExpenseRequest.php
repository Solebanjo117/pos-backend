<?php

namespace App\Http\Requests;

use App\Models\OperationalExpense;
use App\Models\PaymentMethod;
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
            'category' => ['nullable', 'string', 'max:80'],
            'category_id' => ['nullable', 'exists:operational_expense_categories,id'],
            'custom_category_name' => ['nullable', 'string', 'max:100'],
            'subcategory' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'beneficiary_id' => ['nullable', 'exists:operational_expense_beneficiaries,id'],
            'party_type' => ['nullable', Rule::in(['customer', 'supplier', 'beneficiary', 'staff'])],
            'party_id' => ['nullable', 'integer', 'min:1', 'required_with:party_type'],
            'ledger_account_id' => ['nullable', 'exists:ledger_accounts,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'estimated_amount' => ['nullable', 'numeric', 'min:0.01'],
            'final_amount' => ['nullable', 'numeric', 'min:0.01'],
            'variance_reason' => ['nullable', 'string', 'max:500'],
            'due_at' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'required_if:status,paid', 'string', Rule::in(PaymentMethod::realMoneyCodes(PaymentMethod::CONTEXT_SUPPLIER_PAYMENTS))],
            'planned_payment_method' => ['nullable', 'string', Rule::in(PaymentMethod::realMoneyCodes(PaymentMethod::CONTEXT_SUPPLIER_PAYMENTS))],
            'recurrence' => ['nullable', 'string', Rule::in(array_keys(OperationalExpense::RECURRENCES))],
            'status' => ['nullable', 'string', Rule::in(array_keys(OperationalExpense::STATUSES))],
            'paid_at' => ['nullable', 'date'],
            'affects_cash_flow' => ['nullable', 'boolean'],
            'affects_income_statement' => ['nullable', 'boolean'],
            'expense_kind' => ['nullable', 'string', Rule::in(array_keys(OperationalExpense::EXPENSE_KINDS))],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'business_datetime' => ['nullable', 'date'],
            'change_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'expense_date.required' => 'La fecha del gasto es obligatoria.',
            'expense_date.date' => 'La fecha del gasto no es valida.',
            'amount.required' => 'El monto del gasto es obligatorio.',
            'amount.min' => 'El monto del gasto debe ser mayor a cero.',
            'payment_method.required_if' => 'El metodo de pago del gasto es obligatorio cuando el gasto esta pagado.',
            'payment_method.in' => 'El metodo de pago no es valido.',
        ];
    }
}
