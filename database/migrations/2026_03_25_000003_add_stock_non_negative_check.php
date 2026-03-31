<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * R28 - Stock mínimo cero: agregar constraint para evitar inventario negativo
     * Solo se ejecuta si no hay registros con stock negativo existente.
     */
    public function up(): void
    {
        // Verificar si hay registros con stock negativo
        $negativeCount = DB::table('product_inventory')
            ->where('quantity_available', '<', 0)
            ->count();

        if ($negativeCount > 0) {
            // Obtener algunos ejemplos para el reporte
            $examples = DB::table('product_inventory')
                ->select('id', 'product_id', 'warehouse_id', 'quantity_available')
                ->where('quantity_available', '<', 0)
                ->limit(5)
                ->get();

            $exampleList = $examples->map(function ($item) {
                return "ID {$item->id}: product_id={$item->product_id}, warehouse_id={$item->warehouse_id}, qty={$item->quantity_available}";
            })->join('; ');

            throw new \Exception(
                "No se puede aplicar constraint de stock >= 0: se encontraron {$negativeCount} registros con inventario negativo. " .
                "Ejemplos: {$exampleList}. " .
                "Por favor, corrija estos registros manualmente antes de ejecutar esta migración."
            );
        }

        // Si no hay registros negativos, agregar constraint según el driver de BD
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // MySQL/MariaDB: usar CHECK constraint (MySQL 8.0.16+)
            DB::statement('ALTER TABLE product_inventory ADD CONSTRAINT stock_non_negative CHECK (quantity_available >= 0)');
        } elseif ($driver === 'pgsql') {
            // PostgreSQL: usar CHECK constraint
            DB::statement('ALTER TABLE product_inventory ADD CONSTRAINT stock_non_negative CHECK (quantity_available >= 0)');
        } elseif ($driver === 'sqlite') {
            // SQLite: no soporta ADD CONSTRAINT después de crear la tabla
            // La validación se maneja en el código (ya implementada en los servicios)
            // No hacer nada en SQLite
            return;
        }

        // Nota: La columna ya es integer (no unsigned), lo cual es correcto
        // porque permite valores negativos temporalmente durante transacciones.
        // El constraint CHECK es preferible porque valida al final de la transacción.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'pgsql') {
            try {
                DB::statement('ALTER TABLE product_inventory DROP CONSTRAINT stock_non_negative');
            } catch (\Exception $e) {
                // Constraint might not exist, ignore
            }
        }
        // SQLite: no hay nada que revertir
    }
};
