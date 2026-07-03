<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_daily_snapshots', function (Blueprint $table): void {
            $table->decimal('opening_bank', 14, 2)->default(0)->after('opening_cash');
            $table->decimal('bank_sales', 14, 2)->default(0)->after('cash_sales');
            $table->decimal('bank_purchases', 14, 2)->default(0)->after('cash_purchases');
            $table->decimal('customer_bank_payments', 14, 2)->default(0)->after('customer_payments');
            $table->decimal('supplier_bank_payments', 14, 2)->default(0)->after('supplier_payments');
            $table->decimal('cash_expenses', 14, 2)->default(0)->after('expenses');
            $table->decimal('bank_expenses', 14, 2)->default(0)->after('cash_expenses');
            $table->decimal('cogs_bank_sales', 14, 2)->default(0)->after('cogs_cash_sales');
            $table->decimal('closing_bank', 14, 2)->default(0)->after('closing_cash');
        });
    }

    public function down(): void
    {
        Schema::table('financial_daily_snapshots', function (Blueprint $table): void {
            $table->dropColumn([
                'opening_bank',
                'bank_sales',
                'bank_purchases',
                'customer_bank_payments',
                'supplier_bank_payments',
                'cash_expenses',
                'bank_expenses',
                'cogs_bank_sales',
                'closing_bank',
            ]);
        });
    }
};
