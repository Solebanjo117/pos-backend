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
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained()->onDelete('cascade');
            $table->integer('stock_available')->default(0);
            $table->integer('stock_reserved')->default(0)->comment('Reserved for pending orders');
            $table->integer('stock_on_order')->default(0)->comment('On purchase orders');
            $table->integer('reorder_point')->default(0);
            $table->integer('target_level')->default(0)->comment('Desired inventory level');
            $table->timestamps();
            
            $table->unique(['product_id', 'warehouse_id'], 'product_warehouse_unique');
            $table->index('warehouse_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
