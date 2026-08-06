<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_adjustments', function (Blueprint $table): void {
            $table->string('folio', 24)->nullable()->unique()->after('id');
            $table->uuid('idempotency_key')->nullable()->unique()->after('folio');
            $table->string('request_hash', 64)->nullable()->after('idempotency_key');
            $table->string('direction', 16)->default('increase')->after('type');
            $table->string('status', 20)->default('completed')->after('direction');
            $table->boolean('is_legacy')->default(false)->after('status');
            $table->string('reference', 120)->nullable()->after('reason');
            $table->string('timezone', 64)->default('America/Panama')->after('business_datetime');
            $table->string('created_ip', 45)->nullable()->after('timezone');
            $table->json('before_values')->nullable()->after('created_ip');
            $table->json('after_values')->nullable()->after('before_values');
            $table->json('metadata')->nullable()->after('after_values');

            $table->foreignId('affected_ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->foreignId('counterparty_ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('quantity')->nullable();
            $table->decimal('unit_cost', 14, 4)->nullable();

            $table->foreignId('cash_movement_id')->nullable()->constrained('cash_movements')->nullOnDelete();
            $table->foreignId('financial_account_movement_id')->nullable()->constrained('financial_account_movements')->nullOnDelete();
            $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('account_ledger_entry_id')->nullable()->constrained('account_ledger_entries')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('financial_adjustments')->restrictOnDelete();
            $table->foreignId('reversed_by_adjustment_id')->nullable()->constrained('financial_adjustments')->restrictOnDelete();

            $table->index(['status', 'business_datetime']);
            $table->index(['customer_id', 'sales_order_id']);
            $table->index(['supplier_id', 'purchase_order_id']);
        });

        $counters = [];

        DB::table('financial_adjustments')
            ->orderBy('adjustment_date')
            ->orderBy('id')
            ->get(['id', 'amount', 'adjustment_date'])
            ->each(function (object $adjustment) use (&$counters): void {
                $year = substr((string) $adjustment->adjustment_date, 0, 4) ?: now()->format('Y');
                $counters[$year] = ($counters[$year] ?? 0) + 1;

                DB::table('financial_adjustments')->where('id', $adjustment->id)->update([
                    'folio' => 'AJ-'.$year.'-'.str_pad((string) $counters[$year], 6, '0', STR_PAD_LEFT),
                    'direction' => (float) $adjustment->amount < 0 ? 'decrease' : 'increase',
                    'amount' => abs((float) $adjustment->amount),
                    'is_legacy' => true,
                    'status' => 'completed',
                    'timezone' => 'America/Panama',
                ]);
            });

        foreach ($counters as $year => $currentValue) {
            DB::table('document_sequences')->updateOrInsert(
                ['sequence_key' => 'financial_adjustment:'.$year],
                [
                    'current_value' => $currentValue,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('financial_adjustments')
            ->where('direction', 'decrease')
            ->update(['amount' => DB::raw('-ABS(amount)')]);

        DB::table('document_sequences')
            ->where('sequence_key', 'like', 'financial_adjustment:%')
            ->delete();

        Schema::table('financial_adjustments', function (Blueprint $table): void {
            $table->dropIndex(['supplier_id', 'purchase_order_id']);
            $table->dropIndex(['customer_id', 'sales_order_id']);
            $table->dropIndex(['status', 'business_datetime']);

            foreach ([
                'reversed_by_adjustment_id',
                'reversal_of_id',
                'journal_entry_id',
                'account_ledger_entry_id',
                'inventory_movement_id',
                'financial_account_movement_id',
                'cash_movement_id',
                'variant_id',
                'product_id',
                'purchase_order_id',
                'supplier_id',
                'sales_order_id',
                'customer_id',
                'pos_session_id',
                'counterparty_ledger_account_id',
                'affected_ledger_account_id',
            ] as $column) {
                $table->dropConstrainedForeignId($column);
            }

            $table->dropUnique(['idempotency_key']);
            $table->dropUnique(['folio']);
            $table->dropColumn([
                'folio',
                'idempotency_key',
                'request_hash',
                'direction',
                'status',
                'is_legacy',
                'reference',
                'timezone',
                'created_ip',
                'before_values',
                'after_values',
                'metadata',
                'quantity',
                'unit_cost',
            ]);
        });
    }
};
