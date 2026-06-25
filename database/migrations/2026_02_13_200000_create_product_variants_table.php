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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id()->startingValue(100000);
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('brand_id')->constrained('brands')->onDelete('restrict');
            $table->string('sku')->unique();
            $table->string('upc')->nullable()->unique();
            $table->string('status')->default('active');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // Un producto no puede tener la misma marca dos veces
            $table->unique(['product_id', 'brand_id']);

            // Indices para búsquedas
            $table->index('sku');
            $table->index('upc');
            $table->index(['product_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
