<?php

namespace App\Http\Requests;

use App\Models\CashierQueueEntry;
use App\Models\PaymentMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteCashierQueueEntryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $entry = $this->route('cashierQueueEntry');
        if ($this->has('payments') || ! in_array($entry?->entry_type, [CashierQueueEntry::TYPE_EXPENSE, CashierQueueEntry::TYPE_FUNDING], true)) {
            return;
        }
        $fixed = (bool) data_get($entry->metadata, 'fixed_cash_amount', false);
        $payment = [
            'method' => $fixed ? 'cash' : $this->input('payment_method', 'cash'),
            'amount' => $fixed ? $entry->estimated_amount : $this->input('final_amount', $entry->estimated_amount),
            'reference_number' => $this->input('reference_number'),
        ];
        if ($this->has('financial_account_id')) {
            $payment['financial_account_id'] = $this->input('financial_account_id');
        }
        $this->merge(['payments' => [$payment]]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole(['admin', 'manager', 'cashier']) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var CashierQueueEntry|null $entry */
        $entry = $this->route('cashierQueueEntry');
        if ($this->has('payments')) {
            if (! in_array($entry?->entry_type, [CashierQueueEntry::TYPE_EXPENSE, CashierQueueEntry::TYPE_FUNDING, 'purchase_payment'], true)) {
                return ['payments' => ['prohibited']];
            }

            return [
                'payments' => ['required', 'array', 'min:1', 'max:20'],
                'payments.*.method' => ['required', 'string'],
                'payments.*.amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
                'payments.*.financial_account_id' => ['nullable', 'integer'],
                'payments.*.reference_number' => ['nullable', 'string', 'max:120'],
                'payments.*.notes' => ['nullable', 'string', 'max:1000'],
                'final_amount' => ['nullable', 'numeric', 'min:0.01'],
                'variance_reason' => ['nullable', 'string', 'max:500'],
                'reference_number' => ['nullable', 'string', 'max:120'],
            ];
        }
        if ((bool) data_get($entry?->metadata, 'fixed_cash_amount', false)) {
            return [];
        }

        $rules = [
            'final_amount' => ['nullable', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'variance_reason' => ['nullable', 'string', 'max:500'],
        ];
        if ($entry?->entry_type === CashierQueueEntry::TYPE_EXPENSE) {
            $rules['final_amount'] = ['required', 'numeric', 'min:0.01'];
            $rules['payment_method'] = ['required', 'string', Rule::in(PaymentMethod::realMoneyCodes(PaymentMethod::CONTEXT_SUPPLIER_PAYMENTS))];
        }
        if ($entry?->entry_type === CashierQueueEntry::TYPE_RETURN && (float) data_get($entry->metadata, 'money_refund_amount', 0) > 0) {
            $rules['payment_method'] = ['required', 'string', Rule::in(PaymentMethod::realMoneyCodes(PaymentMethod::CONTEXT_RETURNS))];
        }

        return $rules;
    }
}
