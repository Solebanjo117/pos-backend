<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountReceiptRequest extends FormRequest
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
        $context = str_contains((string) $this->path(), 'accounts-payable')
            ? PaymentMethod::CONTEXT_SUPPLIER_PAYMENTS
            : PaymentMethod::CONTEXT_CUSTOMER_RECEIPTS;

        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string', Rule::in(PaymentMethod::realMoneyCodes($context))],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date'],
            'pos_session_id' => ['nullable', 'integer', 'exists:pos_sessions,id'],
            'financial_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
            'applications' => ['nullable', 'array'],
            'applications.*.document_id' => ['required_with:applications', 'integer', 'min:1'],
            'applications.*.amount' => ['required_with:applications', 'numeric', 'min:0.01'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'El monto es obligatorio.',
            'amount.min' => 'El monto debe ser mayor a cero.',
            'method.required' => 'El método de pago es obligatorio.',
            'applications.*.document_id.required_with' => 'Cada aplicación requiere un documento.',
            'applications.*.amount.required_with' => 'Cada aplicación requiere un monto.',
        ];
    }
}
