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
        Schema::create('product_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->decimal('supplier_cost', 10, 2)->default(0);
            $table->integer('lead_time_days')->default(0)->comment('Delivery time in days');
            $table->integer('minimum_order_quantity')->default(1);
            $table->boolean('is_preferred')->default(false);
            $table->timestamps();
            
            $table->unique(['product_id', 'supplier_id'], 'product_supplier_unique');
            $table->index(['product_id', 'is_preferred']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_suppliers');
    }
};
