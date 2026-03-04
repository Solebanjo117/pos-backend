<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Change reference_type from enum to string to support more values
     * like 'sale', 'sale_cancelled', 'purchase_order', 'purchase_order_cancelled'
     */
    public function up(): void
    {
        // Change enum to string to support flexible reference types
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('reference_type', 50)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original enum (data may be lost if non-enum values exist)
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->enum('reference_type', ['purchase', 'sale', 'adjustment', 'transfer', 'return'])->nullable()->change();
        });
    }
};
