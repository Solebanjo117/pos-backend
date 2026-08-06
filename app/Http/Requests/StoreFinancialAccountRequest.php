<?php

namespace App\Http\Requests;

use App\Models\FinancialAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(FinancialAccount::TYPES)],
            'institution_name' => ['nullable', 'string', 'max:120', 'required_if:type,bank'],
            'masked_number' => ['nullable', 'string', 'max:80', 'required_if:type,bank'],
            'currency' => ['nullable', 'string', 'size:3'],
            'opening_balance' => ['nullable', 'numeric'],
            'allow_negative_balance' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'ledger_account_id' => ['required', 'exists:ledger_accounts,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la cuenta es obligatorio.',
            'masked_number.required_if' => 'El identificador enmascarado es obligatorio para cuentas bancarias.',
            'institution_name.required_if' => 'El banco o institución es obligatorio para cuentas bancarias.',
            'type.in' => 'El tipo de cuenta no es válido.',
            'ledger_account_id.required' => 'La cuenta contable es obligatoria.',
        ];
    }
}
