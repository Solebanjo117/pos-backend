<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        // System payment methods (cannot be archived)
        // These codes match existing order_payments.method values in the database
        $systemMethods = [
            [
                'name' => 'Efectivo',
                'code' => 'cash',
                'is_system' => true,
                'requires_reference' => false,
                'requires_cash_count' => true, // Count in opening/closing
                'sort_order' => 1,
            ],
            [
                'name' => 'Tarjeta',
                'code' => 'card',
                'is_system' => true,
                'requires_reference' => false,
                'requires_cash_count' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Transferencia',
                'code' => 'transfer',
                'is_system' => true,
                'requires_reference' => true, // Usually needs reference
                'requires_cash_count' => false,
                'sort_order' => 3,
            ],
            [
                'name' => 'Crédito',
                'code' => 'credit',
                'is_system' => true,
                'requires_reference' => false,
                'requires_cash_count' => false,
                'sort_order' => 4,
            ],
            [
                'name' => 'Saldo a Favor',
                'code' => 'store_credit',
                'is_system' => true,
                'requires_reference' => false,
                'requires_cash_count' => false,
                'sort_order' => 5,
            ],
        ];

        foreach ($systemMethods as $method) {
            PaymentMethod::updateOrCreate(
                ['code' => $method['code']],
                array_merge($method, ['is_active' => true])
            );
        }
    }
}
