<?php

namespace App\Http\Requests;

use App\Models\FinancialTransfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(['admin', 'manager']) === true;
    }

    public function rules(): array
    {
        $endpointTypes = [
            FinancialTransfer::ENDPOINT_FINANCIAL_ACCOUNT,
            FinancialTransfer::ENDPOINT_POS_SESSION,
            'warehouse_cash',
        ];

        return [
            'idempotency_key' => ['required', 'uuid'],
            'from_endpoint_type' => ['required', Rule::in($endpointTypes)],
            'from_endpoint_id' => ['required', 'integer', 'min:1'],
            'to_endpoint_type' => ['required', Rule::in($endpointTypes)],
            'to_endpoint_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'business_datetime' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'El monto es obligatorio.',
            'amount.min' => 'El monto debe ser mayor a cero.',
            'receipt.mimes' => 'El comprobante debe ser PDF o una imagen válida.',
            'receipt.max' => 'El comprobante no puede superar 10 MB.',
        ];
    }
}
