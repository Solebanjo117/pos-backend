<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cash_operation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('operation_type', 30);
            $table->decimal('amount', 14, 2);
            $table->text('notes')->nullable();
            $table->foreignId('operational_expense_id')->nullable()->constrained('operational_expenses')->restrictOnDelete();
            $table->foreignId('funding_category_id')->nullable()->constrained('funding_categories')->restrictOnDelete();
            $table->foreignId('queue_entry_id')->nullable()->constrained('cashier_queue_entries')->nullOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignId('capital_movement_id')->nullable()->constrained('capital_movements')->restrictOnDelete();
            $table->string('status', 30)->default('pending');
            $table->timestamp('requested_at');
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->string('request_hash', 64);
            $table->timestamps();

            $table->index(['warehouse_id', 'status']);
            $table->index(['operation_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_operation_requests');
    }
};
