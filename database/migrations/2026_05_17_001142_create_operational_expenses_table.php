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
        Schema::create('operational_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('expense_date');
            $table->string('category', 50);
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 30)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['expense_date', 'category']);
            $table->index(['warehouse_id', 'expense_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operational_expenses');
    }
};
