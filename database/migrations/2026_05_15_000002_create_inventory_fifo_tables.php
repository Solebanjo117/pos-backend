<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('source_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->string('batch_number')->nullable();
            $table->integer('received_quantity');
            $table->integer('quantity_available');
            $table->decimal('unit_cost_base', 12, 4)->default(0);
            $table->decimal('tax_per_unit', 12, 4)->default(0);
            $table->decimal('misc_per_unit', 12, 4)->default(0);
            $table->decimal('landed_unit_cost', 12, 4)->default(0);
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['variant_id', 'warehouse_id', 'quantity_available']);
            $table->index(['product_id', 'warehouse_id', 'quantity_available']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('inventory_lot_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_lot_id')->constrained('inventory_lots')->cascadeOnDelete();
            $table->foreignId('inventory_movement_id')->constrained('inventory_movements')->cascadeOnDelete();
            $table->foreignId('sales_order_item_id')->nullable()->constrained('sales_order_items')->nullOnDelete();
            $table->integer('quantity');
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('total_cost', 12, 4)->default(0);
            $table->timestamps();

            $table->index(['inventory_movement_id']);
            $table->index(['sales_order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lot_allocations');
        Schema::dropIfExists('inventory_lots');
    }
};
