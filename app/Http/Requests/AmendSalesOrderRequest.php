<?php

namespace App\Http\Requests;

use App\Models\FinancialAccount;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AmendSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(['admin', 'manager', 'buyer', 'seller', 'assistant']) ?? false;
    }

    public function rules(): array
    {
        $methods = PaymentMethod::validCodesForContext(PaymentMethod::CONTEXT_SALES, false, ['supplier_credit']);

        return [
            'idempotency_key' => ['required', 'uuid'],
            'expected_total' => ['required', 'numeric', 'min:0'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['nullable', 'integer', 'distinct'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.tax_percent' => ['required', 'integer', 'between:0,100'],
            'items.*.seller_id' => ['nullable', 'integer', 'exists:users,id'],
            'items.*.is_custom_line' => ['sometimes', 'boolean'],
            'items.*.custom_description' => ['nullable', 'string', 'max:255'],
            'items.*.notes' => ['nullable', 'string', 'max:5000'],
            'payment' => ['present', 'array'],
            'payment.*.method' => ['required', 'string', Rule::in($methods ?: ['cash', 'card', 'transfer', 'credit', 'store_credit'])],
            'payment.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payment.*.reference_number' => ['nullable', 'string', 'max:100'],
            'payment.*.financial_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
            'payment.*.notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'Falta el identificador de la ampliación. Vuelve a abrir la transacción.',
            'expected_total.required' => 'Falta el total original de la transacción.',
            'items.required' => 'Agrega artículos o aumenta una cantidad.',
            'items.*.qty.min' => 'La cantidad debe ser mayor que cero.',
            'payment.*.method.in' => 'El método de pago no está disponible para ventas.',
            'warehouse_id.required' => 'Debes tener una sucursal asignada.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($this->input('items', []) as $index => $item) {
                if (empty($item['order_item_id']) && ! empty($item['seller_id'])
                    && (int) $item['seller_id'] !== (int) $this->user()->id
                    && ! $this->user()->hasRole(['admin', 'manager'])) {
                    $validator->errors()->add("items.{$index}.seller_id", 'No puedes asignar artículos a otro vendedor.');
                }
            }
            foreach ($this->input('payment', []) as $index => $payment) {
                if (! is_array($payment)) {
                    continue;
                }
                $method = PaymentMethod::query()->where('code', $payment['method'] ?? '')->first();
                if ($method?->requires_reference && empty($payment['reference_number'])) {
                    $validator->errors()->add("payment.{$index}.reference_number", 'Este método requiere una referencia.');
                }
                if (PaymentMethod::liquidityBucket($payment['method'] ?? null) === PaymentMethod::LIQUIDITY_BANK
                    && (PaymentMethod::requiresAccount($payment['method']) || ! empty($payment['financial_account_id']))
                    && ! FinancialAccount::query()->whereKey($payment['financial_account_id'] ?? 0)->where('is_active', true)->where('is_legacy', false)->exists()) {
                    $validator->errors()->add("payment.{$index}.financial_account_id", 'Selecciona una cuenta financiera activa.');
                }
            }
        }];
    }
}
