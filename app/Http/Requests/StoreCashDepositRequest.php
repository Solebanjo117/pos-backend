<?php

namespace App\Http\Requests;

class StoreCashDepositRequest extends IndexCashDepositSourcesRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'idempotency_key' => ['required', 'uuid'],
            'financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'],
            'business_datetime' => ['nullable', 'date', 'before_or_equal:now'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['required', 'array', 'min:1', 'max:500'],
            'allocations.*.scope' => ['required', 'in:sales,receipts'],
            'allocations.*.id' => ['required', 'integer', 'min:1'],
            'allocations.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'],
        ];
    }
}
