<?php

namespace App\Http\Requests;

use App\Models\FinancialAccount;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'approve_immediately' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'refund_method' => ['required', 'string', Rule::in(array_values(array_unique(array_merge(
                PaymentMethod::realMoneyCodes(PaymentMethod::CONTEXT_RETURNS),
                ['balance', 'store_credit', 'receivable'],
            ))))],
            'financial_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
            'refund_notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sales_order_item_id' => ['required', 'exists:sales_order_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.user_id' => ['nullable', 'exists:users,id'],
            'items.*.seller_id' => ['nullable', 'exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $method = $this->string('refund_method')->toString();
            if (
                in_array($method, ['balance', 'store_credit', 'receivable'], true)
                || PaymentMethod::liquidityBucket($method) !== PaymentMethod::LIQUIDITY_BANK
            ) {
                return;
            }

            $accountId = $this->integer('financial_account_id');
            $eligible = $accountId > 0 && FinancialAccount::query()
                ->whereKey($accountId)
                ->where('type', 'bank')
                ->where('is_active', true)
                ->where('is_legacy', false)
                ->exists();

            if (! $eligible) {
                $validator->errors()->add(
                    'financial_account_id',
                    'Selecciona una cuenta bancaria activa para este reembolso.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'No se pudo identificar esta operación. Cierre y vuelva a abrir el formulario.',
            'idempotency_key.uuid' => 'La identificación de la operación no es válida.',
            'refund_method.required' => 'Seleccione el método de reembolso.',
            'refund_method.in' => 'El método de reembolso seleccionado no es válido.',
            'financial_account_id.exists' => 'La cuenta bancaria seleccionada no existe.',
            'items.required' => 'Seleccione al menos un artículo para devolver.',
            'items.min' => 'Seleccione al menos un artículo para devolver.',
            'items.*.sales_order_item_id.required' => 'Cada línea debe indicar el artículo vendido.',
            'items.*.sales_order_item_id.exists' => 'Uno de los artículos seleccionados no existe.',
            'items.*.quantity.required' => 'Cada línea debe indicar la cantidad a devolver.',
            'items.*.quantity.integer' => 'La cantidad a devolver debe ser un número entero.',
            'items.*.quantity.min' => 'La cantidad a devolver debe ser al menos 1.',
        ];
    }
}
