<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignPaymentAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(['admin', 'manager']) ?? false;
    }

    public function rules(): array
    {
        return ['financial_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')->where(fn ($query) => $query->where('is_active', true)->where('is_legacy', false))]];
    }

    public function messages(): array
    {
        return ['financial_account_id.exists' => 'Seleccione una cuenta financiera activa.'];
    }
}
