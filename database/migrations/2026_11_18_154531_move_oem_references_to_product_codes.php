<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las "referencias OEM" eran otra lista de números de la pieza. Desde 2026-11-18 todos los números
     * viven en product_codes (Números de parte del producto). Se pasan como números adicionales del
     * producto (tipo alternate); si el número ya lo tiene un producto, no se duplica. Luego se borra la tabla.
     */
    public function up(): void
    {
        if (! Schema::hasTable('oem_references')) {
            return;
        }

        $normalize = fn (?string $code): string => preg_replace('/[^\pL\pN]+/u', '', mb_strtoupper(trim((string) $code))) ?? '';

        foreach (DB::table('oem_references')->orderBy('id')->get() as $reference) {
            $normalized = $normalize($reference->oem_code);
            $productExists = DB::table('products')->where('id', $reference->product_id)->exists();
            $owner = DB::table('product_codes')
                ->where('normalized_code', $normalized)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->value('product_id');

            if ($normalized === '' || ! $productExists || (int) $owner === (int) $reference->product_id) {
                continue;
            }

            // El número es de otro producto: era una referencia cruzada entre dos piezas. Se conserva
            // como producto alterno (compatible) en lugar de duplicar el número.
            if ($owner !== null) {
                DB::table('alternative_products')->updateOrInsert(
                    ['primary_product_id' => $reference->product_id, 'alternative_product_id' => $owner],
                    ['relationship_type' => 'compatible', 'created_at' => now(), 'updated_at' => now()],
                );

                continue;
            }

            DB::table('product_codes')->insert([
                'product_id' => $reference->product_id,
                'variant_id' => null,
                'code' => trim((string) $reference->oem_code),
                'normalized_code' => $normalized,
                'type' => 'alternate',
                'status' => 'active',
                'is_primary' => false,
                'metadata' => json_encode(['migrated_from' => ['oem_references'], 'manufacturer' => $reference->manufacturer]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::drop('oem_references');
    }

    public function down(): void
    {
        if (Schema::hasTable('oem_references')) {
            return;
        }

        Schema::create('oem_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('oem_code', 50);
            $table->string('manufacturer', 100)->nullable();
            $table->timestamps();

            $table->index('oem_code');
            $table->index('product_id');
        });
    }
};
