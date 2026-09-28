<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(['admin', 'manager']) ?? false;
    }

    public function rules(): array
    {
        $rules = [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:100'],
            'liquidity_bucket' => ['sometimes', Rule::in(['cash', 'bank', 'credit', 'store_credit'])],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'default_financial_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where(fn ($query) => $query->where('is_active', true)->where('is_legacy', false))],
        ];
        foreach (['is_active', 'requires_reference', 'requires_cash_count', 'requires_financial_account', ...array_map(fn ($context) => PaymentMethod::contextColumn($context), PaymentMethod::contextOptions())] as $field) {
            $rules[$field] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['default_financial_account_id.exists' => 'Seleccione una cuenta financiera activa y no histórica.'];
    }
}
