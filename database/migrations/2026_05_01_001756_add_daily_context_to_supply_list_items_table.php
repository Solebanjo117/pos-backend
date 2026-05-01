<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('supply_list_items', function (Blueprint $table) {
            if (! Schema::hasColumn('supply_list_items', 'warehouse_id')) {
                $table->foreignId('warehouse_id')
                    ->nullable()
                    ->after('supplier_id')
                    ->constrained('warehouses')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('supply_list_items', 'supply_date')) {
                $table->date('supply_date')
                    ->nullable()
                    ->after('warehouse_id')
                    ->index();
            }
        });

        $defaultWarehouseId = DB::table('warehouses')->orderBy('id')->value('id');

        if ($defaultWarehouseId) {
            DB::table('supply_list_items')
                ->whereNull('warehouse_id')
                ->update(['warehouse_id' => $defaultWarehouseId]);
        }

        DB::table('supply_list_items')
            ->whereNull('supply_date')
            ->update(['supply_date' => DB::raw('DATE(created_at)')]);

        Schema::table('supply_list_items', function (Blueprint $table) {
            $table->index(['warehouse_id', 'supply_date', 'supplier_id'], 'supply_list_daily_supplier_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supply_list_items', function (Blueprint $table) {
            $table->dropIndex('supply_list_daily_supplier_index');

            if (Schema::hasColumn('supply_list_items', 'warehouse_id')) {
                $table->dropConstrainedForeignId('warehouse_id');
            }

            if (Schema::hasColumn('supply_list_items', 'supply_date')) {
                $table->dropColumn('supply_date');
            }
        });
    }
};
