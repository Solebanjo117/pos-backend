<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->onDelete('cascade');
            $table->decimal('amount', 12, 2);
            $table->enum('method', ['cash', 'card', 'transfer', 'credit']);
            $table->text('notes')->nullable();
            $table->datetime('paid_at');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('sales_order_id');
            $table->index('paid_at');
            $table->index('method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_payments');
    }
};
