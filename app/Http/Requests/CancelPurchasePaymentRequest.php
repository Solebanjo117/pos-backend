<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelPurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(['admin', 'manager', 'buyer']) ?? false;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Indique el motivo de cancelación.'];
    }
}
