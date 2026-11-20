<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quita el sistema viejo de "lotes de producción" (product_batches, warehouse_batches,
     * inventory_movements.product_batch_id y products.batch_tracking). Nunca tuvo datos (0 filas en local
     * y en producción); los lotes reales son los FIFO de inventory_lots, que siguen igual.
     */
    public function up(): void
    {
        foreach (['product_batches', 'warehouse_batches'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("La tabla {$table} tiene datos; revisar antes de eliminarla.");
            }
        }

        if (Schema::hasColumn('inventory_movements', 'product_batch_id')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->dropConstrainedForeignId('product_batch_id');
            });
        }

        Schema::dropIfExists('warehouse_batches');
        Schema::dropIfExists('product_batches');

        if (Schema::hasColumn('products', 'batch_tracking')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('batch_tracking');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'batch_tracking')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('batch_tracking')->default(false)->after('status');
            });
        }

        if (! Schema::hasTable('product_batches')) {
            Schema::create('product_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->string('batch_number');
                $table->date('manufacturing_date')->nullable();
                $table->date('expiry_date')->nullable();
                $table->decimal('cost', 10, 2)->default(0);
                $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['product_id', 'batch_number']);
            });
        }

        if (! Schema::hasTable('warehouse_batches')) {
            Schema::create('warehouse_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_batch_id')->constrained()->cascadeOnDelete();
                $table->decimal('quantity', 10, 2)->default(0);
                $table->decimal('quantity_reserved', 10, 2)->default(0);
                $table->timestamps();

                $table->unique(['warehouse_id', 'product_batch_id']);
            });
        }

        if (! Schema::hasColumn('inventory_movements', 'product_batch_id')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->foreignId('product_batch_id')->nullable()->after('product_id')->constrained('product_batches')->nullOnDelete();
            });
        }
    }
};
