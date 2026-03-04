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
        Schema::table('product_inventory', function (Blueprint $table) {
            // Drop old unique constraint
            // Note: The index name is usually tableName_column1_column2_unique
            $table->dropUnique('product_inventory_product_id_warehouse_id_unique');

            // Add new unique constraint including variant_id
            $table->unique(['product_id', 'warehouse_id', 'variant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_inventory', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'warehouse_id', 'variant_id']);
            $table->unique(['product_id', 'warehouse_id']);
        });
    }
};
