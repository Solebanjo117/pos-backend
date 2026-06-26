<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add system_id to product_variants with backfill using brand prefix.
     */
    public function up(): void
    {
        // 1. Add system_id column (nullable initially)
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('system_id', 20)->nullable()->after('id');
        });

        // 2. Backfill: generate system_id for all existing variants
        $variants = DB::table('product_variants')
            ->join('brands', 'product_variants.brand_id', '=', 'brands.id')
            ->select('product_variants.id', 'product_variants.brand_id', 'brands.prefix')
            ->orderBy('product_variants.brand_id')
            ->orderBy('product_variants.id')
            ->get();

        // Track counters per brand
        $brandCounters = [];

        foreach ($variants as $variant) {
            $brandId = $variant->brand_id;
            $prefix = $variant->prefix ?? 'UNK';

            if (! isset($brandCounters[$brandId])) {
                $brandCounters[$brandId] = 1;
            }

            $systemId = $prefix.str_pad($brandCounters[$brandId], 8, '0', STR_PAD_LEFT);

            DB::table('product_variants')
                ->where('id', $variant->id)
                ->update(['system_id' => $systemId]);

            $brandCounters[$brandId]++;
        }

        // 3. Update brand_sequences with next values
        foreach ($brandCounters as $brandId => $nextValue) {
            DB::table('brand_sequences')
                ->where('brand_id', $brandId)
                ->update(['next_value' => $nextValue, 'updated_at' => now()]);
        }

        // 4. Add unique index
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unique('system_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('system_id');
        });
    }
};
