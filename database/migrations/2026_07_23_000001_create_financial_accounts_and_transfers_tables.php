<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 30);
            $table->string('masked_number', 80)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->boolean('allow_negative_balance')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_legacy')->default(false);
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        Schema::create('financial_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('folio', 20)->unique();
            $table->uuid('idempotency_key')->unique();
            $table->string('request_hash', 64);
            $table->string('from_endpoint_type', 30);
            $table->unsignedBigInteger('from_endpoint_id');
            $table->string('to_endpoint_type', 30);
            $table->unsignedBigInteger('to_endpoint_id');
            $table->decimal('amount', 14, 2);
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_original_name')->nullable();
            $table->string('receipt_mime', 100)->nullable();
            $table->unsignedBigInteger('receipt_size')->nullable();
            $table->string('status', 30)->default('completed');
            $table->timestamp('business_datetime');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('financial_transfers')->restrictOnDelete();
            $table->foreignId('reversed_by_transfer_id')->nullable()->constrained('financial_transfers')->restrictOnDelete();
            $table->timestamps();

            $table->index(['business_datetime', 'status']);
            $table->index(['from_endpoint_type', 'from_endpoint_id']);
            $table->index(['to_endpoint_type', 'to_endpoint_id']);
        });

        Schema::create('financial_account_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->string('direction', 10);
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_before', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('source_line_key', 120)->default('main');
            $table->timestamp('business_datetime');
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['source_type', 'source_id', 'source_line_key'],
                'financial_movements_source_unique',
            );
            $table->index(['financial_account_id', 'business_datetime']);
        });

        if (! DB::connection()->pretending()) {
            $bankLedgerId = DB::table('ledger_accounts')->where('code', '1110')->value('id');
            if (! $bankLedgerId) {
                $bankLedgerId = DB::table('ledger_accounts')->insertGetId([
                    'code' => '1110',
                    'name' => 'Bank',
                    'type' => 'asset',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('financial_accounts')->insert([
                'name' => 'Banco sin asignar',
                'type' => 'bank',
                'currency' => 'USD',
                'opening_balance' => 0,
                'current_balance' => 0,
                'allow_negative_balance' => true,
                'is_active' => true,
                'is_legacy' => true,
                'ledger_account_id' => $bankLedgerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([
            'sales_order_payments',
            'purchase_order_payments',
            'account_payment_batches',
            'operational_expenses',
            'capital_movements',
            'financial_adjustments',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('financial_account_id')
                    ->nullable()
                    ->constrained('financial_accounts')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'sales_order_payments',
            'purchase_order_payments',
            'account_payment_batches',
            'operational_expenses',
            'capital_movements',
            'financial_adjustments',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('financial_account_id');
            });
        }

        Schema::dropIfExists('financial_account_movements');
        Schema::dropIfExists('financial_transfers');
        Schema::dropIfExists('financial_accounts');
    }
};
