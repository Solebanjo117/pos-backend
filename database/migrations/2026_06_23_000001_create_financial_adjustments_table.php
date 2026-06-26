<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('type', ['cash', 'inventory']);
            $table->decimal('amount', 12, 2);
            $table->string('reason', 255);
            $table->date('adjustment_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['adjustment_date', 'type']);
            $table->index(['warehouse_id', 'adjustment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_adjustments');
    }
};
