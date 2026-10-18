<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FLOWS = ['cash_investment_in', 'cash_investment_out', 'bank_investment_in', 'bank_investment_out', 'cash_transfers', 'bank_transfers', 'unapplied_cash_receipts', 'unapplied_bank_receipts'];

    public function up(): void
    {
        Schema::table('financial_daily_snapshots', function (Blueprint $table): void {
            foreach (self::FLOWS as $field) {
                $table->decimal($field, 14, 2)->default(0);
            }
        });
        Schema::table('financial_transfers', function (Blueprint $table): void {
            $table->date('collection_date_from')->nullable();
            $table->date('collection_date_to')->nullable();
        });
        Schema::create('cash_deposit_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('financial_transfer_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('source_scope', 20);
            $table->unsignedBigInteger('source_id');
            $table->decimal('amount', 14, 2);
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_transfer_id')->nullable()->constrained('financial_transfers')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['financial_transfer_id', 'source_scope', 'source_id'], 'deposit_transfer_source_unique');
            $table->index(['source_scope', 'source_id', 'released_at'], 'deposit_source_remaining_index');
        });
        Schema::table('account_payment_batches', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('receipt_accounting_version')->default(0);
        });
        Schema::table('operational_payments', fn (Blueprint $table) => $table->timestamp('business_datetime')->nullable());
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_deposit_allocations');
        Schema::table('operational_payments', fn (Blueprint $table) => $table->dropColumn('business_datetime'));
        Schema::table('account_payment_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropColumn('receipt_accounting_version');
        });
        Schema::table('financial_transfers', fn (Blueprint $table) => $table->dropColumn(['collection_date_from', 'collection_date_to']));
        Schema::table('financial_daily_snapshots', fn (Blueprint $table) => $table->dropColumn(self::FLOWS));
    }
};
