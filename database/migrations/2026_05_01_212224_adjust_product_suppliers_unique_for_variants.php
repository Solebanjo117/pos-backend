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
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('product_suppliers', function (Blueprint $table) {
            $table->dropUnique('product_supplier_unique');
            $table->unique(['variant_id', 'supplier_id'], 'product_supplier_variant_unique');
            $table->index(['product_id', 'supplier_id'], 'product_supplier_product_supplier_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('product_suppliers', function (Blueprint $table) {
            $table->dropIndex('product_supplier_product_supplier_index');
            $table->dropUnique('product_supplier_variant_unique');
            $table->unique(['product_id', 'supplier_id'], 'product_supplier_unique');
        });
    }
};
