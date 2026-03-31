<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add individual status to purchase_order_items for tracking reception per item.
     * This supports daily POs where items can be received independently.
     */
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('subtotal');
            $table->index('status');
        });

        // Set existing items with quantity_received > 0 as 'received'
        DB::table('purchase_order_items')
            ->where('quantity_received', '>', 0)
            ->whereRaw('quantity_received >= quantity_ordered')
            ->update(['status' => 'received']);

        // Set partially received items as 'partial'
        DB::table('purchase_order_items')
            ->where('quantity_received', '>', 0)
            ->whereRaw('quantity_received < quantity_ordered')
            ->update(['status' => 'partial']);
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
