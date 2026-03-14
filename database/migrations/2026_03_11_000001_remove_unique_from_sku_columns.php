<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove UNIQUE constraints from sku_base (products) and sku (product_variants).
     * The id autoincrement remains the true primary key.
     * PostgreSQL allows dropping named indices directly.
     */
    public function up(): void
    {
        // Drop unique index on products.sku_base
        // (The index was originally named products_sku_unique from the original migration
        // before the column was renamed from sku → sku_base)
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_sku_unique');
        });

        // Drop unique index on product_variants.sku
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique('product_variants_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unique('sku_base', 'products_sku_unique');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unique('sku', 'product_variants_sku_unique');
        });
    }
};
