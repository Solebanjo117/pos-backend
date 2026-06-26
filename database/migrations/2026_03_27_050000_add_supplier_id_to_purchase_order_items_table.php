<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add supplier_id to purchase_order_items for daily global PO support.
     * Each item can now specify its own supplier instead of inheriting from PO header.
     */
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('purchase_order_id')
                ->constrained('suppliers')
                ->onDelete('restrict');

            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropIndex(['supplier_id']);
            $table->dropColumn('supplier_id');
        });
    }
};
