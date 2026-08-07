<?php

namespace App\Http\Requests;

use App\Models\FinancialAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageFinancialAdjustments() ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_keys(FinancialAdjustment::TYPES))],
            'direction' => [
                Rule::requiredIf(fn (): bool => $this->input('type') !== FinancialAdjustment::TYPE_MANUAL_CORRECTION),
                'nullable',
                Rule::in([FinancialAdjustment::DIRECTION_INCREASE, FinancialAdjustment::DIRECTION_DECREASE]),
            ],
            'amount' => ['required_unless:type,'.FinancialAdjustment::TYPE_INVENTORY, 'nullable', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
            'business_datetime' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
            'warehouse_id' => [Rule::requiredIf(fn (): bool => $this->input('type') === FinancialAdjustment::TYPE_INVENTORY), 'nullable', 'exists:warehouses,id'],
            'pos_session_id' => [Rule::requiredIf(fn (): bool => in_array($this->input('type'), [FinancialAdjustment::TYPE_CASH, FinancialAdjustment::TYPE_INVESTMENT], true) && ! $this->filled('financial_account_id')), 'nullable', 'exists:pos_sessions,id'],
            'financial_account_id' => [Rule::requiredIf(fn (): bool => $this->input('type') === FinancialAdjustment::TYPE_BANK), 'nullable', 'exists:financial_accounts,id'],
            'affected_ledger_account_id' => [Rule::requiredIf(fn (): bool => in_array($this->input('type'), [FinancialAdjustment::TYPE_SALES, FinancialAdjustment::TYPE_PURCHASES, FinancialAdjustment::TYPE_EXPENSES, FinancialAdjustment::TYPE_MANUAL_CORRECTION], true)), 'nullable', 'exists:ledger_accounts,id'],
            'counterparty_ledger_account_id' => ['required', 'exists:ledger_accounts,id'],
            'customer_id' => [Rule::requiredIf(fn (): bool => $this->input('type') === FinancialAdjustment::TYPE_ACCOUNTS_RECEIVABLE), 'nullable', 'exists:customers,id'],
            'sales_order_id' => [Rule::requiredIf(fn (): bool => $this->input('type') === FinancialAdjustment::TYPE_ACCOUNTS_RECEIVABLE), 'nullable', 'exists:sales_orders,id'],
            'supplier_id' => [Rule::requiredIf(fn (): bool => $this->input('type') === FinancialAdjustment::TYPE_ACCOUNTS_PAYABLE), 'nullable', 'exists:suppliers,id'],
            'purchase_order_id' => [Rule::requiredIf(fn (): bool => $this->input('type') === FinancialAdjustment::TYPE_ACCOUNTS_PAYABLE), 'nullable', 'exists:purchase_orders,id'],
            'product_id' => [Rule::requiredIf(fn (): bool => $this->input('type') === FinancialAdjustment::TYPE_INVENTORY), 'nullable', 'exists:products,id'],
            'variant_id' => ['nullable', 'exists:product_variants,id'],
            'quantity' => [Rule::requiredIf(fn (): bool => $this->input('type') === FinancialAdjustment::TYPE_INVENTORY), 'nullable', 'integer', 'min:1'],
            'unit_cost' => [Rule::requiredIf(fn (): bool => $this->input('type') === FinancialAdjustment::TYPE_INVENTORY && $this->input('direction') === FinancialAdjustment::DIRECTION_INCREASE), 'nullable', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'El tipo de ajuste es obligatorio.',
            'type.in' => 'El tipo de ajuste no es valido.',
            'amount.required' => 'El monto del ajuste es obligatorio.',
            'amount.numeric' => 'El monto debe ser numérico.',
            'amount.gt' => 'El monto debe ser mayor que cero.',
            'reason.required' => 'El motivo del ajuste es obligatorio.',
            'reason.max' => 'El motivo no puede superar 255 caracteres.',
            'business_datetime.required' => 'La fecha y hora del ajuste son obligatorias.',
            'business_datetime.date' => 'La fecha y hora del ajuste no son válidas.',
            'counterparty_ledger_account_id.required' => 'La cuenta de contrapartida es obligatoria.',
            'idempotency_key.required' => 'La clave de idempotencia es obligatoria.',
        ];
    }
}
