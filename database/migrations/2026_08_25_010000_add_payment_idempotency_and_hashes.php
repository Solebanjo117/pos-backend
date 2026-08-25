<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->string('request_hash', 64)->nullable()->after('idempotency_key');
        });

        Schema::table('account_payment_batches', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->string('request_hash', 64)->nullable();
        });

        Schema::table('sales_order_payments', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->string('request_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_payments', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'request_hash']);
        });

        Schema::table('account_payment_batches', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'request_hash']);
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropColumn('request_hash');
        });
    }
};
