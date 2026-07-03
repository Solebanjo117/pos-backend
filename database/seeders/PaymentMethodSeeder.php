<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $realMoneyDefaults = [
            'liquidity_bucket' => 'bank',
            'available_in_sales' => true,
            'available_in_customer_receipts' => true,
            'available_in_supplier_payments' => true,
            'available_in_purchase_orders' => true,
            'available_in_returns' => true,
        ];

        $systemMethods = [
            [
                'name' => 'Efectivo',
                'code' => 'cash',
                'is_system' => true,
                'requires_reference' => false,
                'requires_cash_count' => true,
                'liquidity_bucket' => 'cash',
                'available_in_sales' => true,
                'available_in_customer_receipts' => true,
                'available_in_supplier_payments' => true,
                'available_in_purchase_orders' => true,
                'available_in_returns' => true,
                'sort_order' => 1,
            ],
            array_merge($realMoneyDefaults, [
                'name' => 'Tarjeta',
                'code' => 'card',
                'is_system' => true,
                'requires_reference' => false,
                'requires_cash_count' => false,
                'sort_order' => 2,
            ]),
            array_merge($realMoneyDefaults, [
                'name' => 'Transferencia',
                'code' => 'transfer',
                'is_system' => true,
                'requires_reference' => true,
                'requires_cash_count' => false,
                'sort_order' => 3,
            ]),
            array_merge($realMoneyDefaults, [
                'name' => 'Yappy',
                'code' => 'yappy',
                'is_system' => true,
                'requires_reference' => true,
                'requires_cash_count' => false,
                'sort_order' => 4,
            ]),
            array_merge($realMoneyDefaults, [
                'name' => 'ACH',
                'code' => 'ach',
                'is_system' => true,
                'requires_reference' => true,
                'requires_cash_count' => false,
                'sort_order' => 5,
            ]),
            array_merge($realMoneyDefaults, [
                'name' => 'Cheque',
                'code' => 'cheque',
                'is_system' => true,
                'requires_reference' => true,
                'requires_cash_count' => false,
                'sort_order' => 6,
            ]),
            array_merge($realMoneyDefaults, [
                'name' => 'Otro',
                'code' => 'other',
                'is_system' => true,
                'requires_reference' => false,
                'requires_cash_count' => false,
                'sort_order' => 7,
            ]),
            array_merge($realMoneyDefaults, [
                'name' => 'Visa',
                'code' => 'visa',
                'is_system' => true,
                'is_active' => false,
                'requires_reference' => true,
                'requires_cash_count' => false,
                'sort_order' => 8,
            ]),
            array_merge($realMoneyDefaults, [
                'name' => 'Mastercard',
                'code' => 'mastercard',
                'is_system' => true,
                'is_active' => false,
                'requires_reference' => true,
                'requires_cash_count' => false,
                'sort_order' => 9,
            ]),
            array_merge($realMoneyDefaults, [
                'name' => 'AMEX',
                'code' => 'amex',
                'is_system' => true,
                'is_active' => false,
                'requires_reference' => true,
                'requires_cash_count' => false,
                'sort_order' => 10,
            ]),
            [
                'name' => 'Credito',
                'code' => 'credit',
                'is_system' => true,
                'requires_reference' => false,
                'requires_cash_count' => false,
                'liquidity_bucket' => 'credit',
                'available_in_sales' => true,
                'available_in_customer_receipts' => false,
                'available_in_supplier_payments' => false,
                'available_in_purchase_orders' => false,
                'available_in_returns' => false,
                'sort_order' => 11,
            ],
            [
                'name' => 'Saldo a Favor',
                'code' => 'store_credit',
                'is_system' => true,
                'requires_reference' => false,
                'requires_cash_count' => false,
                'liquidity_bucket' => 'store_credit',
                'available_in_sales' => true,
                'available_in_customer_receipts' => false,
                'available_in_supplier_payments' => false,
                'available_in_purchase_orders' => false,
                'available_in_returns' => true,
                'sort_order' => 12,
            ],
            [
                'name' => 'Credito proveedor',
                'code' => 'supplier_credit',
                'is_system' => true,
                'requires_reference' => false,
                'requires_cash_count' => false,
                'liquidity_bucket' => 'credit',
                'available_in_sales' => false,
                'available_in_customer_receipts' => false,
                'available_in_supplier_payments' => false,
                'available_in_purchase_orders' => true,
                'available_in_returns' => false,
                'sort_order' => 13,
            ],
        ];

        foreach ($systemMethods as $method) {
            $paymentMethod = PaymentMethod::firstOrNew(['code' => $method['code']]);
            if ($paymentMethod->exists) {
                foreach ([
                    'is_active',
                    'available_in_sales',
                    'available_in_customer_receipts',
                    'available_in_supplier_payments',
                    'available_in_purchase_orders',
                    'available_in_returns',
                ] as $preservedField) {
                    $method[$preservedField] = $paymentMethod->{$preservedField};
                }
            }

            $paymentMethod->fill(array_merge(
                $method,
                [
                    'is_active' => $paymentMethod->exists
                        ? $paymentMethod->is_active
                        : ($method['is_active'] ?? true),
                ]
            ));
            $paymentMethod->save();
        }
    }
}
