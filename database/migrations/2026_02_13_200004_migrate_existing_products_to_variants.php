<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Convierte todos los productos existentes en variantes default
     */
    public function up(): void
    {
        DB::transaction(function () {
            // Obtener todos los productos que tienen brand_id
            $products = DB::table('products')
                ->whereNotNull('brand_id')
                ->get();

            foreach ($products as $product) {
                // Crear variante default para cada producto
                $variantId = DB::table('product_variants')->insertGetId([
                    'product_id' => $product->id,
                    'brand_id' => $product->brand_id,
                    'sku' => $product->sku, // Mantener SKU original
                    'upc' => $product->upc,
                    'status' => $product->status,
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Actualizar pricing para apuntar a la variante
                DB::table('product_pricing')
                    ->where('product_id', $product->id)
                    ->update(['variant_id' => $variantId]);

                // Actualizar inventario para apuntar a la variante
                DB::table('product_inventory')
                    ->where('product_id', $product->id)
                    ->update(['variant_id' => $variantId]);

                // Actualizar purchase order items
                DB::table('purchase_order_items')
                    ->where('product_id', $product->id)
                    ->update(['variant_id' => $variantId]);

                // Actualizar sales order items (si existen)
                if (Schema::hasTable('sales_order_items')) {
                    DB::table('sales_order_items')
                        ->where('product_id', $product->id)
                        ->update(['variant_id' => $variantId]);
                }
            }

            // Log de migración
            $variantsCreated = DB::table('product_variants')->count();
            echo "\n✓ Migración completada: {$variantsCreated} variantes creadas\n";
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revertir: eliminar variantes y volver variant_id a null
        DB::transaction(function () {
            DB::table('product_pricing')->update(['variant_id' => null]);
            DB::table('product_inventory')->update(['variant_id' => null]);
            DB::table('purchase_order_items')->update(['variant_id' => null]);

            if (Schema::hasTable('sales_order_items')) {
                DB::table('sales_order_items')->update(['variant_id' => null]);
            }

            DB::table('product_variants')->truncate();
        });
    }
};
