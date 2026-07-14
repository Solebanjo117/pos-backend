<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('sequence_key', 80)->unique();
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();
        });

        Schema::create('sales_credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('credit_note_number', 20)->unique();
            $table->foreignId('sales_return_id')->unique()->constrained('sales_returns')->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->decimal('total_amount', 14, 2);
            $table->decimal('receivable_amount', 14, 2)->default(0);
            $table->decimal('refund_amount', 14, 2)->default(0);
            $table->decimal('store_credit_amount', 14, 2)->default(0);
            $table->string('status', 30)->default('pending');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->string('settlement_method', 80)->nullable();
            $table->string('reference_number', 120)->nullable();
            $table->timestamps();
            $table->index(['status', 'issued_at']);
        });

        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->string('settlement_status', 30)->default('pending')->after('status');
            $table->foreignId('settled_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable()->after('settled_by');
            $table->string('settlement_method', 80)->nullable()->after('settled_at');
            $table->string('settlement_reference', 120)->nullable()->after('settlement_method');
            $table->index(['settlement_status', 'settled_at']);
        });

        // Approved returns predating the cashier queue already applied their financial impact.
        DB::table('sales_returns')
            ->where('status', 'approved')
            ->update([
                'settlement_status' => 'settled',
                'settled_at' => DB::raw('COALESCE(approved_at, updated_at, created_at)'),
                'settlement_method' => DB::raw('refund_method'),
            ]);
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->dropIndex(['settlement_status', 'settled_at']);
            $table->dropConstrainedForeignId('settled_by');
            $table->dropColumn([
                'settlement_status', 'settled_at', 'settlement_method', 'settlement_reference',
            ]);
        });

        Schema::dropIfExists('sales_credit_notes');
        Schema::dropIfExists('document_sequences');
    }
};
