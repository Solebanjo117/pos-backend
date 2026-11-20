<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'pvc_one_default_per_product';

    /**
     * Carro principal del producto: a lo sumo uno por producto. En los productos que ya tienen
     * carros, el más antiguo (que no sea "universal") queda como principal.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('product_vehicle_compatibility', 'is_default')) {
            Schema::table('product_vehicle_compatibility', function (Blueprint $table) {
                $table->boolean('is_default')->default(false);
            });
        }

        $oldestPerProduct = DB::table('product_vehicle_compatibility')
            ->where(function ($query): void {
                $query->whereNull('scope_type')->orWhere('scope_type', '!=', 'universal');
            })
            ->groupBy('product_id')
            ->selectRaw('MIN(id) as id')
            ->pluck('id');

        DB::table('product_vehicle_compatibility')->update(['is_default' => false]);
        foreach ($oldestPerProduct->chunk(500) as $ids) {
            DB::table('product_vehicle_compatibility')->whereIn('id', $ids->all())->update(['is_default' => true]);
        }

        // Índice único parcial (PostgreSQL y SQLite): un solo principal por producto.
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX
            .' ON product_vehicle_compatibility (product_id) WHERE is_default = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

        if (Schema::hasColumn('product_vehicle_compatibility', 'is_default')) {
            Schema::table('product_vehicle_compatibility', function (Blueprint $table) {
                $table->dropColumn('is_default');
            });
        }
    }
};
