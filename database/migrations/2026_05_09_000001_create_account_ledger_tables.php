<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_payment_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('account_type', 20);
            $table->unsignedBigInteger('account_id');
            $table->string('direction', 20);
            $table->string('batch_number', 50)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('method', 50);
            $table->string('reference_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['account_type', 'account_id']);
            $table->index('paid_at');
        });

        Schema::create('account_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('account_type', 20);
            $table->unsignedBigInteger('account_id');
            $table->string('movement_type', 50);
            $table->string('document_type', 50)->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('document_number', 100)->nullable();
            $table->foreignId('payment_batch_id')->nullable()->constrained('account_payment_batches')->nullOnDelete();
            $table->decimal('debit', 12, 2)->default(0);
            $table->decimal('credit', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 2)->default(0);
            $table->timestamp('occurred_at');
            $table->string('reference_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_entry_id')->nullable()->constrained('account_ledger_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['account_type', 'account_id', 'occurred_at']);
            $table->index(['document_type', 'document_id']);
            $table->index('movement_type');
        });

        Schema::create('account_payment_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_batch_id')->constrained('account_payment_batches')->cascadeOnDelete();
            $table->string('document_type', 50);
            $table->unsignedBigInteger('document_id');
            $table->string('document_number', 100)->nullable();
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index(['document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_payment_applications');
        Schema::dropIfExists('account_ledger_entries');
        Schema::dropIfExists('account_payment_batches');
    }
};
