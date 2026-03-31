<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * R21 - Motors as reusable entities in products
     * Adds engine_id as optional FK to products table, pointing to vehicle_engines.
     * This is separate from vehicle compatibility (product_vehicle_compatibility pivot).
     * Represents "the motor/engine of the product itself" (e.g., a replacement engine part).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('engine_id')
                ->nullable()
                ->after('category_id')
                ->constrained('vehicle_engines')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['engine_id']);
            $table->dropColumn('engine_id');
        });
    }
};
