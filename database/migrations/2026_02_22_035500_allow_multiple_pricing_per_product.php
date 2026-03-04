<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Remove the UNIQUE constraint on product_id (which prevents multiple
     * pricing records per product) and replace it with a composite unique
     * on (product_id, variant_id), allowing each variant to have its own
     * independent pricing record.
     */
    public function up(): void
    {
        Schema::table('product_pricing', function (Blueprint $table) {
            // Drop the old unique index on product_id alone
            $table->dropUnique(['product_id']);
            
            // Add a regular index on product_id for query performance
            $table->index('product_id', 'product_pricing_product_id_index');
            
            // Add composite unique so each (product, variant) pair can only have one pricing
            $table->unique(['product_id', 'variant_id'], 'product_pricing_product_variant_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_pricing', function (Blueprint $table) {
            $table->dropUnique('product_pricing_product_variant_unique');
            $table->dropIndex('product_pricing_product_id_index');
            $table->unique('product_id');
        });
    }
};
