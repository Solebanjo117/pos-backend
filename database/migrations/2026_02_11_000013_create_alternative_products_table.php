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
        Schema::create('alternative_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('primary_product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('alternative_product_id')->constrained('products')->onDelete('cascade');
            $table->enum('relationship_type', ['compatible', 'substitute', 'upgrade'])->default('compatible');
            $table->timestamps();

            $table->unique(['primary_product_id', 'alternative_product_id'], 'alternative_products_unique');
            $table->index('primary_product_id');
            $table->index('alternative_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alternative_products');
    }
};
