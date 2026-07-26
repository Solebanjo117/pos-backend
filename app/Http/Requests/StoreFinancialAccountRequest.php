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
            'masked_number' => ['nullable', 'string', 'max:80'],
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
            'type.in' => 'El tipo de cuenta no es válido.',
            'ledger_account_id.required' => 'La cuenta contable es obligatoria.',
        ];
    }
}
