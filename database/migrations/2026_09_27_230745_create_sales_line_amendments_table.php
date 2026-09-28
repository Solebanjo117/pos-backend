<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_line_amendments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_item_id')->constrained()->restrictOnDelete();
            $table->uuid('amendment_key');
            $table->timestamp('occurred_at')->index();
            $table->boolean('history_complete')->default(true);
            foreach (['quantity', 'gross_sales', 'discounts', 'net_sales', 'tax', 'cost'] as $field) {
                $table->decimal('before_'.$field, 18, 2)->nullable();
                $table->decimal($field, 18, 2)->nullable();
            }
            $table->unique(['sales_order_item_id', 'amendment_key']);
        });
        Schema::table('sales_return_items', function (Blueprint $table): void {
            $table->decimal('reporting_unit_cost', 18, 4)->nullable();
        });
        Schema::table('warehouse_cash_entries', function (Blueprint $table): void {
            $table->string('reporting_kind')->nullable();
            $table->string('reporting_reference')->nullable();
            $table->string('reporting_method')->nullable();
            $table->string('reporting_document_url')->nullable();
            $table->unsignedBigInteger('reporting_session_id')->nullable();
            $table->timestamp('reporting_business_datetime')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_cash_entries', fn (Blueprint $table) => $table->dropColumn(['reporting_kind', 'reporting_reference', 'reporting_method', 'reporting_document_url', 'reporting_session_id', 'reporting_business_datetime']));
        Schema::table('sales_return_items', fn (Blueprint $table) => $table->dropColumn('reporting_unit_cost'));
        Schema::dropIfExists('sales_line_amendments');
    }
};
