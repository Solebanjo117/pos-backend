<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_payment_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('requested_by')->constrained('users');
            $table->decimal('amount', 14, 2);
            $table->text('notes')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->string('request_hash', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payment_requests');
    }
};
