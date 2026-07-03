<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_methods', 'liquidity_bucket')) {
                $table->string('liquidity_bucket', 30)->default('bank')->after('requires_cash_count');
            }

            if (! Schema::hasColumn('payment_methods', 'available_in_sales')) {
                $table->boolean('available_in_sales')->default(true)->after('liquidity_bucket');
            }

            if (! Schema::hasColumn('payment_methods', 'available_in_customer_receipts')) {
                $table->boolean('available_in_customer_receipts')->default(true)->after('available_in_sales');
            }

            if (! Schema::hasColumn('payment_methods', 'available_in_supplier_payments')) {
                $table->boolean('available_in_supplier_payments')->default(true)->after('available_in_customer_receipts');
            }

            if (! Schema::hasColumn('payment_methods', 'available_in_purchase_orders')) {
                $table->boolean('available_in_purchase_orders')->default(true)->after('available_in_supplier_payments');
            }

            if (! Schema::hasColumn('payment_methods', 'available_in_returns')) {
                $table->boolean('available_in_returns')->default(true)->after('available_in_purchase_orders');
            }
        });

        $this->configureExistingPaymentMethods();
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $columns = [
                'available_in_returns',
                'available_in_purchase_orders',
                'available_in_supplier_payments',
                'available_in_customer_receipts',
                'available_in_sales',
                'liquidity_bucket',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('payment_methods', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function configureExistingPaymentMethods(): void
    {
        $realMoney = [
            'liquidity_bucket' => 'bank',
            'available_in_sales' => true,
            'available_in_customer_receipts' => true,
            'available_in_supplier_payments' => true,
            'available_in_purchase_orders' => true,
            'available_in_returns' => true,
        ];

        foreach (['card', 'transfer', 'yappy', 'ach', 'cheque', 'other', 'visa', 'mastercard', 'amex'] as $code) {
            DB::table('payment_methods')->where('code', $code)->update($realMoney);
        }

        DB::table('payment_methods')->where('code', 'cash')->update(array_merge($realMoney, [
            'liquidity_bucket' => 'cash',
        ]));

        DB::table('payment_methods')->where('code', 'credit')->update([
            'liquidity_bucket' => 'credit',
            'available_in_sales' => true,
            'available_in_customer_receipts' => false,
            'available_in_supplier_payments' => false,
            'available_in_purchase_orders' => false,
            'available_in_returns' => false,
        ]);

        DB::table('payment_methods')->where('code', 'store_credit')->update([
            'liquidity_bucket' => 'store_credit',
            'available_in_sales' => true,
            'available_in_customer_receipts' => false,
            'available_in_supplier_payments' => false,
            'available_in_purchase_orders' => false,
            'available_in_returns' => true,
        ]);

        DB::table('payment_methods')->where('code', 'supplier_credit')->update([
            'liquidity_bucket' => 'credit',
            'available_in_sales' => false,
            'available_in_customer_receipts' => false,
            'available_in_supplier_payments' => false,
            'available_in_purchase_orders' => true,
            'available_in_returns' => false,
        ]);
    }
};
