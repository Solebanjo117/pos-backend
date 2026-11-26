<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tipo de inventario del producto (como el "Type" de Lightspeed):
     * `single` lleva existencia (todos los productos actuales) y `non_inventory` no la lleva
     * (mano de obra, instalación, cargos). Texto con validación en la aplicación, no ENUM.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('inventory_type', 20)->default('single')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('inventory_type');
        });
    }
};
