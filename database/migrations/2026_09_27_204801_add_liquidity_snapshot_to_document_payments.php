<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_order_payments', 'purchase_order_payments', 'account_payment_batches'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('liquidity_bucket')->nullable();
            });
        }
        Schema::table('sales_order_payments', fn (Blueprint $table) => $table->foreignId('warehouse_id')->nullable()->constrained());
        Schema::table('sales_order_payments', fn (Blueprint $table) => $table->foreignId('account_payment_batch_id')->nullable()->constrained()->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('sales_order_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('warehouse_id'));
        Schema::table('sales_order_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('account_payment_batch_id'));
        foreach (['sales_order_payments', 'purchase_order_payments', 'account_payment_batches'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('liquidity_bucket'));
        }
    }
};
