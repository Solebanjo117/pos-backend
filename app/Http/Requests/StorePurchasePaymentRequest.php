<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(['admin', 'manager', 'buyer']) ?? false;
    }

    public function rules(): array
    {
        return ['amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'], 'notes' => ['nullable', 'string', 'max:500'], 'idempotency_key' => ['required', 'uuid']];
    }

    public function messages(): array
    {
        return ['amount.min' => 'El abono debe ser mayor a cero.', 'idempotency_key.required' => 'Falta la identificación de la solicitud.'];
    }
}
