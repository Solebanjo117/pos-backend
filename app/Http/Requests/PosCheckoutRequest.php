<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PosCheckoutRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $items = $this->input('items');
        $payments = $this->input('payments');

        if (is_array($payments)) {
            $configuredSalesMethods = PaymentMethod::validCodesForContext(
                PaymentMethod::CONTEXT_SALES,
                false,
                ['supplier_credit'],
            );
            $methodAliases = [
                'clave' => 'card',
                'transferencia' => 'transfer',
                'notaCredito' => 'credit',
                'creditoCliente' => 'credit',
            ];

            foreach ($payments as $index => $payment) {
                if (! is_array($payment)) {
                    continue;
                }

                $method = $payment['method'] ?? null;
                if (
                    is_string($method)
                    && isset($methodAliases[$method])
                    && ! in_array($method, $configuredSalesMethods, true)
                ) {
                    $payments[$index]['method'] = $methodAliases[$method];
                }
            }

            $this->merge(['payments' => $payments]);
        }

        if (is_array($items)) {
            foreach ($items as $index => $item) {
                if (! is_array($item) || ! array_key_exists('tax_percent', $item) || ! is_numeric($item['tax_percent'])) {
                    continue;
                }

                $items[$index]['tax_percent'] = (int) round((float) $item['tax_percent']);
            }

            $this->merge(['items' => $items]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $validPaymentMethods = PaymentMethod::validCodesForContext(
            PaymentMethod::CONTEXT_SALES,
            false,
            ['supplier_credit'],
        );

        if ($validPaymentMethods === []) {
            $validPaymentMethods = ['cash', 'card', 'transfer', 'credit', 'store_credit'];
        }

        // Log for debugging
        Log::debug('PosCheckoutRequest validation', [
            'valid_methods' => $validPaymentMethods,
            'requested_payments' => $this->input('payments'),
        ]);

        return [
            'idempotency_key' => [
                Rule::requiredIf(fn (): bool => $this->user()?->role === 'cashier' && $this->filled('source_quote_id')),
                'nullable',
                'uuid',
            ],
            'user_id' => 'nullable|integer|min:1',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_vehicle_id' => 'nullable|exists:customer_vehicles,id',
            'referred_by_customer_id' => 'nullable|exists:customers,id',
            'date' => 'nullable|date',
            'status' => 'nullable|in:draft,pending,confirmed',
            'source_quote_id' => 'nullable|exists:sales_quotes,id',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'payment_method' => ['nullable', Rule::in(array_values(array_unique(array_merge($validPaymentMethods, ['multiple']))))],
            'payment_status' => 'nullable|in:pending,partial,paid,refunded',
            'initial_payment' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
            'audit_logs' => 'nullable|array',
            'payments' => 'nullable|array',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'payments.*.method' => ['required_with:payments', Rule::in($validPaymentMethods)],
            'payments.*.reference_number' => 'nullable|string|max:100',
            'payments.*.notes' => 'nullable|string',
            'payments.*.financial_account_id' => 'nullable|integer|exists:financial_accounts,id',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.warehouse_id' => 'required|exists:warehouses,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.tax_percent' => 'nullable|integer|min:0|max:100',
            'items.*.seller_id' => 'nullable|exists:users,id',
            'items.*.source_quote_item_id' => 'nullable|integer|exists:sales_quote_items,id',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ($this->input('items', []) as $index => $item) {
                if (
                    ! is_array($item)
                    || ! array_key_exists('discount_amount', $item)
                    || $item['discount_amount'] === null
                    || ! is_numeric($item['discount_amount'])
                    || ! is_numeric($item['quantity'] ?? null)
                    || ! is_numeric($item['unit_price'] ?? null)
                ) {
                    continue;
                }

                $grossSubtotal = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
                $discountAmount = round((float) $item['discount_amount'], 2);

                if ($discountAmount > $grossSubtotal) {
                    $validator->errors()->add(
                        "items.{$index}.discount_amount",
                        'El descuento no puede superar el importe bruto de la línea.',
                    );
                }
            }

            foreach ($this->input('payments', []) as $index => $payment) {
                if (! is_array($payment) || empty($payment['method'])) {
                    continue;
                }

                if (PaymentMethod::liquidityBucket($payment['method']) !== PaymentMethod::LIQUIDITY_BANK) {
                    continue;
                }

                $accountId = $payment['financial_account_id'] ?? null;
                $isEligible = $accountId && \App\Models\FinancialAccount::query()
                    ->whereKey($accountId)
                    ->where('is_active', true)
                    ->where('is_legacy', false)
                    ->exists();

                if (! $isEligible && ($accountId || PaymentMethod::requiresAccount($payment['method']))) {
                    $validator->errors()->add("payments.{$index}.financial_account_id", 'Selecciona una cuenta financiera activa para este cobro.');
                }
            }

            if ($this->user()?->role !== 'cashier') {
                return;
            }

            if (! $this->filled('source_quote_id')) {
                $validator->errors()->add('source_quote_id', 'Caja solo puede cobrar ventas abiertas desde la cola.');
            }

            $items = $this->input('items', []);
            if (! is_array($items) || $items === []) {
                $validator->errors()->add('items', 'La venta de la cola no contiene articulos para cobrar.');

                return;
            }

            foreach ($items as $index => $item) {
                if (empty($item['source_quote_item_id'])) {
                    $validator->errors()->add("items.{$index}.source_quote_item_id", 'Cada articulo debe provenir de la cotizacion enviada a Caja.');
                }
            }
        });
    }
}
