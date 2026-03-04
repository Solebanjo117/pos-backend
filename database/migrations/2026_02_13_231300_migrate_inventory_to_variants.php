<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Migrate existing product_inventory records to use variant_id.
     */
    public function up(): void
    {
        // Find all inventory records with product_id but no variant_id
        $inventoryRecords = DB::table('product_inventory')
            ->whereNull('variant_id')
            ->whereNotNull('product_id')
            ->get();

        foreach ($inventoryRecords as $inventory) {
            // Find the default variant for this product
            $defaultVariant = DB::table('product_variants')
                ->where('product_id', $inventory->product_id)
                ->where('is_default', true)
                ->first();

            if ($defaultVariant) {
                // Update inventory to point to the variant
                DB::table('product_inventory')
                    ->where('id', $inventory->id)
                    ->update([
                        'variant_id' => $defaultVariant->id,
                        'product_id' => null, // Clear product_id as we now use variant_id
                    ]);
            }
        }

        echo "Migrated " . $inventoryRecords->count() . " inventory records from product_id to variant_id\n";
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Optionally reverse the migration
        $inventoryRecords = DB::table('product_inventory')
            ->whereNotNull('variant_id')
            ->get();

        foreach ($inventoryRecords as $inventory) {
            $variant = DB::table('product_variants')
                ->where('id', $inventory->variant_id)
                ->first();

            if ($variant) {
                DB::table('product_inventory')
                    ->where('id', $inventory->id)
                    ->update([
                        'product_id' => $variant->product_id,
                        'variant_id' => null,
                    ]);
            }
        }
    }
};
