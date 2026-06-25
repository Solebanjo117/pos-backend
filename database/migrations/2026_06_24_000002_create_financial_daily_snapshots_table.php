<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_daily_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');

            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->decimal('opening_inventory', 14, 2)->default(0);
            $table->decimal('opening_accounts_receivable', 14, 2)->default(0);
            $table->decimal('opening_accounts_payable', 14, 2)->default(0);

            $table->decimal('cash_sales', 14, 2)->default(0);
            $table->decimal('credit_sales', 14, 2)->default(0);
            $table->decimal('cash_purchases', 14, 2)->default(0);
            $table->decimal('credit_purchases', 14, 2)->default(0);
            $table->decimal('customer_payments', 14, 2)->default(0);
            $table->decimal('supplier_payments', 14, 2)->default(0);
            $table->decimal('expenses', 14, 2)->default(0);
            $table->decimal('cogs_cash_sales', 14, 2)->default(0);
            $table->decimal('cogs_credit_sales', 14, 2)->default(0);
            $table->decimal('investment_in', 14, 2)->default(0);
            $table->decimal('investment_out', 14, 2)->default(0);
            $table->decimal('cash_adjustments', 14, 2)->default(0);
            $table->decimal('inventory_adjustments', 14, 2)->default(0);

            $table->decimal('closing_cash', 14, 2)->default(0);
            $table->decimal('closing_inventory', 14, 2)->default(0);
            $table->decimal('accounts_receivable', 14, 2)->default(0);
            $table->decimal('accounts_payable', 14, 2)->default(0);
            $table->decimal('gross_profit', 14, 2)->default(0);
            $table->decimal('net_profit', 14, 2)->default(0);
            $table->decimal('net_investment', 14, 2)->default(0);

            $table->boolean('is_locked')->default(false);
            $table->timestamp('computed_at')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->json('validation_warnings')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_daily_snapshots');
    }
};
