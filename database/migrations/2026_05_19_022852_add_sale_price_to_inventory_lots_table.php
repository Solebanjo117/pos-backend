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
        Schema::table('inventory_lots', function (Blueprint $table) {
            $table->decimal('sale_price', 12, 2)->default(0)->after('landed_unit_cost');
        });

        DB::table('inventory_lots')
            ->select(['id', 'product_id', 'variant_id'])
            ->orderBy('id')
            ->get()
            ->each(function ($lot): void {
                $pricing = DB::table('product_pricing')
                    ->where('product_id', $lot->product_id)
                    ->when(
                        $lot->variant_id,
                        fn ($query) => $query->where('variant_id', $lot->variant_id),
                        fn ($query) => $query->whereNull('variant_id')
                    )
                    ->value('base_price');

                DB::table('inventory_lots')
                    ->where('id', $lot->id)
                    ->update(['sale_price' => $pricing ?? 0]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table) {
            $table->dropColumn('sale_price');
        });
    }
};
