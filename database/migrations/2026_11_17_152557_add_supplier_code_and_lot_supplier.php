<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proveedores por marca (como "Vendor IDs" del sistema anterior):
     * - product_suppliers.supplier_code: código con que el proveedor llama a la pieza.
     * - product_suppliers.last_purchased_at: fecha de la última compra (su costo es supplier_cost).
     * - inventory_lots.supplier_id: proveedor de cada lote FIFO (null = sin proveedor, se puede cargar después).
     */
    public function up(): void
    {
        Schema::table('product_suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('product_suppliers', 'supplier_code')) {
                $table->string('supplier_code', 255)->nullable()->after('supplier_id');
                $table->string('supplier_code_normalized', 255)->nullable()->after('supplier_code')->index();
            }
            if (! Schema::hasColumn('product_suppliers', 'last_purchased_at')) {
                $table->timestamp('last_purchased_at')->nullable()->after('is_preferred');
            }
        });

        Schema::table('inventory_lots', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_lots', 'supplier_id')) {
                $table->foreignId('supplier_id')->nullable()->after('warehouse_id')->constrained('suppliers')->nullOnDelete();
            }
        });

        // Lotes que ya existen: el proveedor de su movimiento de entrada (compras).
        DB::table('inventory_lots')
            ->whereNull('supplier_id')
            ->whereNotNull('source_movement_id')
            ->orderBy('id')
            ->chunkById(500, function ($lots): void {
                $suppliers = DB::table('inventory_movements')
                    ->whereIn('id', $lots->pluck('source_movement_id'))
                    ->whereNotNull('supplier_id')
                    ->pluck('supplier_id', 'id');

                foreach ($lots as $lot) {
                    if (isset($suppliers[$lot->source_movement_id])) {
                        DB::table('inventory_lots')->where('id', $lot->id)->update(['supplier_id' => $suppliers[$lot->source_movement_id]]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_lots', 'supplier_id')) {
                $table->dropConstrainedForeignId('supplier_id');
            }
        });

        Schema::table('product_suppliers', function (Blueprint $table) {
            if (Schema::hasColumn('product_suppliers', 'supplier_code')) {
                $table->dropIndex(['supplier_code_normalized']);
                $table->dropColumn(['supplier_code', 'supplier_code_normalized']);
            }
            if (Schema::hasColumn('product_suppliers', 'last_purchased_at')) {
                $table->dropColumn('last_purchased_at');
            }
        });
    }
};
