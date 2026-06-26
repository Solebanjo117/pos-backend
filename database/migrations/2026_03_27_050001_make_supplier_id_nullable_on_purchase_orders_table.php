<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make supplier_id nullable on purchase_orders to support daily global POs.
     * Daily POs don't have a single supplier - each item specifies its own.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // Drop existing foreign key constraint
            $table->dropForeign(['supplier_id']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            // Change column to nullable
            $table->unsignedBigInteger('supplier_id')->nullable()->change();

            // Re-add foreign key with nullable support
            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable(false)->change();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->onDelete('restrict');
        });
    }
};
