<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'sales_orders',
        'purchase_orders',
        'sales_order_payments',
        'purchase_order_payments',
        'account_payment_batches',
        'inventory_movements',
        'operational_expenses',
        'financial_adjustments',
        'capital_movements',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'business_datetime')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dateTime('business_datetime')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'business_datetime')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('business_datetime');
            });
        }
    }
};
