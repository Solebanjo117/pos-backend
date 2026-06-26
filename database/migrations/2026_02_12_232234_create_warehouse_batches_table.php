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
        Schema::create('warehouse_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_batch_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 2)->default(0);
            $table->decimal('quantity_reserved', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_batch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_batches');
    }
};
