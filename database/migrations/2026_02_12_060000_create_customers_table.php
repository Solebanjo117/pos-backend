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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            
            // Basic information
            $table->string('code', 50)->unique();
            $table->enum('type', ['individual', 'business'])->default('individual');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            
            // Fiscal information
            $table->string('tax_id', 100)->nullable(); // RFC, RUT, etc.
            $table->string('tax_name')->nullable(); // Razón social
            
            // Address
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->default('México');
            $table->string('postal_code', 20)->nullable();
            
            // Credit
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->integer('credit_days')->default(0);
            $table->decimal('current_balance', 12, 2)->default(0);
            
            // Additional contact
            $table->string('contact_person')->nullable();
            $table->string('contact_phone', 50)->nullable();
            
            // Metadata
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'inactive', 'blocked'])->default('active');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('code');
            $table->index('email');
            $table->index('status');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
