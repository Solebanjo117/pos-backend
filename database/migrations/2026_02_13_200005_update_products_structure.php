<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Limpia la tabla products removiendo campos que ahora están en variants
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Drop ALL indices that reference brand_id (SQLite requirement)
            if (Schema::hasColumn('products', 'brand_id')) {
                $table->dropIndex('products_brand_id_index');
                $table->dropIndex('products_brand_id_status_index'); // Composite index
                $table->dropForeign(['brand_id']);
                $table->dropColumn('brand_id');
            }

            // Eliminar upc (now moved to variant)
            if (Schema::hasColumn('products', 'upc')) {
                $table->dropIndex('products_upc_index'); // Drop index first
                $table->dropColumn('upc');
            }

            // Renombrar sku a sku_base para claridad
            $table->renameColumn('sku', 'sku_base');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('sku_base', 'sku');
            $table->string('upc')->nullable();
            $table->foreignId('brand_id')->nullable()->constrained('brands');
        });
    }
};
