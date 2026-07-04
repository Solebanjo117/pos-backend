<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_returns', 'receivable_applied_amount')) {
                $table->decimal('receivable_applied_amount', 12, 2)->default(0)->after('total_amount');
            }

            if (! Schema::hasColumn('sales_returns', 'money_refund_amount')) {
                $table->decimal('money_refund_amount', 12, 2)->default(0)->after('receivable_applied_amount');
            }

            if (! Schema::hasColumn('sales_returns', 'store_credit_amount')) {
                $table->decimal('store_credit_amount', 12, 2)->default(0)->after('money_refund_amount');
            }

            if (! Schema::hasColumn('sales_returns', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('refund_notes')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('sales_returns', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }

            if (! Schema::hasColumn('sales_returns', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('sales_returns', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            }
        });

        Schema::table('sales_order_payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_order_payments', 'sales_return_id')) {
                $table->foreignId('sales_return_id')->nullable()->after('sales_order_id')->constrained('sales_returns')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_payments', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_order_payments', 'sales_return_id')) {
                $table->dropConstrainedForeignId('sales_return_id');
            }
        });

        Schema::table('sales_returns', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_returns', 'approved_by')) {
                $table->dropConstrainedForeignId('approved_by');
            }

            if (Schema::hasColumn('sales_returns', 'rejected_by')) {
                $table->dropConstrainedForeignId('rejected_by');
            }

            $columns = collect([
                'receivable_applied_amount',
                'money_refund_amount',
                'store_credit_amount',
                'approved_at',
                'rejected_at',
            ])->filter(fn (string $column): bool => Schema::hasColumn('sales_returns', $column))->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
