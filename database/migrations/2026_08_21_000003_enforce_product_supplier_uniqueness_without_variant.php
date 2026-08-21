<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX_NAME = 'product_supplier_product_unique_without_variant';

    public function up(): void
    {
        $hasDuplicates = DB::table('product_suppliers')
            ->whereNull('variant_id')
            ->select(['product_id', 'supplier_id'])
            ->groupBy('product_id', 'supplier_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException('Existen relaciones producto-proveedor duplicadas sin variante. Deben conciliarse antes de crear el índice único.');
        }

        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("El índice parcial de product_suppliers no está implementado para {$driver}.");
        }

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON product_suppliers (product_id, supplier_id) WHERE variant_id IS NULL',
            self::INDEX_NAME
        ));
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);
    }
};
