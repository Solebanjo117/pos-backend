<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained();
            $table->morphs('source');
            $table->unsignedInteger('line_number');
            $table->string('direction');
            $table->string('method');
            $table->string('liquidity_bucket');
            $table->decimal('amount', 14, 2);
            $table->foreignId('financial_account_id')->nullable()->constrained();
            $table->foreignId('cash_movement_id')->nullable()->constrained();
            $table->foreignId('pos_session_id')->nullable()->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->string('reference_number', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'line_number'], 'operational_payment_source_line');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_payments');
    }
};
