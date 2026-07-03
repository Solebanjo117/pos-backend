<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_payments', function (Blueprint $table): void {
            $table->index('paid_at', 'purchase_order_payments_paid_at_idx');
            $table->index('method', 'purchase_order_payments_method_idx');
            $table->index(['purchase_order_id', 'paid_at'], 'purchase_order_payments_order_paid_at_idx');
        });

        Schema::table('financial_daily_snapshots', function (Blueprint $table): void {
            $table->index(['warehouse_id', 'date'], 'financial_snapshots_warehouse_date_idx');
            $table->index(['version', 'computed_at'], 'financial_snapshots_version_computed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_payments', function (Blueprint $table): void {
            $table->dropIndex('purchase_order_payments_paid_at_idx');
            $table->dropIndex('purchase_order_payments_method_idx');
            $table->dropIndex('purchase_order_payments_order_paid_at_idx');
        });

        Schema::table('financial_daily_snapshots', function (Blueprint $table): void {
            $table->dropIndex('financial_snapshots_warehouse_date_idx');
            $table->dropIndex('financial_snapshots_version_computed_idx');
        });
    }
};
