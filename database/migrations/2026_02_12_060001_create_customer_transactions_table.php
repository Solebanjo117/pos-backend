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
        Schema::create('customer_transactions', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            
            // Transaction type
            $table->enum('type', ['sale', 'payment', 'credit_note', 'adjustment']);
            
            // References
            $table->string('reference_type', 100)->nullable(); // 'sales_order', 'payment', etc.
            $table->unsignedBigInteger('reference_id')->nullable();
            
            // Amounts
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_before', 12, 2);
            $table->decimal('balance_after', 12, 2);
            
            // Description
            $table->text('description')->nullable();
            
            $table->datetime('transaction_date');
            $table->foreignId('created_by')->nullable()->constrained('users');
            
            $table->timestamps();
            
            // Indexes
            $table->index('customer_id');
            $table->index('type');
            $table->index('transaction_date');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_transactions');
    }
};
