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
        Schema::create('product_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('warehouses')->onDelete('cascade');
            $table->integer('quantity_available')->default(0);
            $table->integer('quantity_reserved')->default(0);
            $table->integer('reorder_point')->default(10);
            $table->integer('reorder_quantity')->default(50);
            $table->string('location_in_warehouse')->nullable();
            $table->timestamp('last_restocked_at')->nullable();
            $table->timestamps();

            // Unique constraint to prevent duplicate product-warehouse combinations
            $table->unique(['product_id', 'warehouse_id']);

            // Indexes for better query performance
            $table->index('warehouse_id');
            $table->index('product_id');
            $table->index(['quantity_available', 'reorder_point']); // For low stock queries
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_inventory');
    }
};
