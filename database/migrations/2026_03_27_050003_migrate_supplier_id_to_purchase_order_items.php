<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migrate historical data: copy supplier_id from PO header to all its items.
     * This ensures existing POs work with the new per-item supplier model.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('
                UPDATE purchase_order_items
                SET supplier_id = (
                    SELECT supplier_id
                    FROM purchase_orders
                    WHERE purchase_orders.id = purchase_order_items.purchase_order_id
                )
                WHERE supplier_id IS NULL
            ');
        } else {
            DB::statement('
                UPDATE purchase_order_items poi
                SET supplier_id = (
                    SELECT po.supplier_id
                    FROM purchase_orders po
                    WHERE po.id = poi.purchase_order_id
                )
                WHERE poi.supplier_id IS NULL
            ');
        }
    }

    public function down(): void
    {
        // Clear supplier_id from items (revert to header-only model)
        DB::table('purchase_order_items')->update(['supplier_id' => null]);
    }
};
