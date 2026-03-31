<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * R29 Enhancement: Add pricing fields to supply list items
     * for inline editing before conversion to Purchase Orders.
     */
    public function up(): void
    {
        Schema::table('supply_list_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->default(0)->after('quantity_needed');
            $table->decimal('tax_rate', 5, 2)->nullable()->default(7.00)->after('unit_cost'); // ITBM 7%
            $table->decimal('sale_price', 12, 2)->nullable()->default(0)->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('supply_list_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'tax_rate', 'sale_price']);
        });
    }
};
