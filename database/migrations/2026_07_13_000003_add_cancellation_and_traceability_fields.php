<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancellation_return_id')->nullable()->constrained('sales_returns')->nullOnDelete();
            $table->index(['status', 'cancelled_at']);
        });

        Schema::table('operational_expense_beneficiaries', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('beneficiary_kind', 40)->default('other')->index();
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->integer('quantity_before')->nullable();
            $table->integer('quantity_after')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropColumn(['quantity_before', 'quantity_after']);
        });

        Schema::table('operational_expense_beneficiaries', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('beneficiary_kind');
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropIndex(['status', 'cancelled_at']);
            $table->dropConstrainedForeignId('cancellation_return_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
