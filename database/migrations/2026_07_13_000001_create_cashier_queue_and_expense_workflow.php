<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_queue_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_type', 40);
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('status', 30)->default('pending');
            $table->decimal('estimated_amount', 14, 2)->default(0);
            $table->decimal('final_amount', 14, 2)->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'entry_type'], 'cashier_queue_source_unique');
            $table->index(['status', 'scheduled_for']);
            $table->index(['warehouse_id', 'status']);
        });

        Schema::table('operational_expenses', function (Blueprint $table): void {
            $table->decimal('estimated_amount', 14, 2)->nullable()->after('amount');
            $table->decimal('final_amount', 14, 2)->nullable()->after('estimated_amount');
            $table->decimal('variance_amount', 14, 2)->nullable()->after('final_amount');
            $table->text('variance_reason')->nullable()->after('variance_amount');
            $table->timestamp('due_at')->nullable()->after('expense_date');
            $table->string('planned_payment_method', 80)->nullable()->after('payment_method');
            $table->timestamp('queued_at')->nullable()->after('paid_at');
            $table->timestamp('cancelled_at')->nullable()->after('queued_at');
            $table->timestamp('reversed_at')->nullable()->after('cancelled_at');
            $table->index(['status', 'due_at']);
        });

        Schema::create('operational_expense_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operational_expense_id')->constrained('operational_expenses')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('reason')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();
            $table->index(['operational_expense_id', 'created_at']);
        });

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->string('subtype', 40)->nullable()->after('type');
            $table->string('reference_number', 120)->nullable()->after('reason');
            $table->string('payment_method', 80)->nullable()->after('reference_number');
            $table->decimal('balance_before', 14, 2)->nullable()->after('amount');
            $table->decimal('balance_after', 14, 2)->nullable()->after('balance_before');
            $table->string('source_type', 80)->nullable()->after('notes');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->timestamp('business_datetime')->nullable()->after('source_id');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn([
                'subtype', 'reference_number', 'payment_method', 'balance_before',
                'balance_after', 'source_type', 'source_id', 'business_datetime',
            ]);
        });

        Schema::dropIfExists('operational_expense_events');

        Schema::table('operational_expenses', function (Blueprint $table): void {
            $table->dropIndex(['status', 'due_at']);
            $table->dropColumn([
                'estimated_amount', 'final_amount', 'variance_amount', 'variance_reason',
                'due_at', 'planned_payment_method', 'queued_at', 'cancelled_at', 'reversed_at',
            ]);
        });

        Schema::dropIfExists('cashier_queue_entries');
    }
};
