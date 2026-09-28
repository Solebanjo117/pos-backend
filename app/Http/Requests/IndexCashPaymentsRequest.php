<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCashPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(['admin', 'manager', 'cashier']) ?? false;
    }

    public function rules(): array
    {
        return ['date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'], 'method' => ['nullable', 'string', 'max:100'], 'pos_session_id' => ['nullable', 'integer'], 'user_id' => ['nullable', 'integer'], 'scope' => ['nullable', Rule::in(['sales', 'operations', 'purchases'])], 'pending_account' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['date_to.after_or_equal' => 'La fecha final debe ser posterior a la inicial.'];
    }
}
