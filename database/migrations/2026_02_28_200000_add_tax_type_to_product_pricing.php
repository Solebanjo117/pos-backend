<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds tax_type enum to product_pricing so products can be:
     *  - standard (7% ITBM default)
     *  - exempt  (0%)
     *  - custom  (user-defined rate, capped at 15%)
     *
     * Also updates the existing tax_rate default to 7.00 (Panamá ITBM).
     */
    public function up(): void
    {
        Schema::table('product_pricing', function (Blueprint $table) {
            $table->string('tax_type', 20)->default('standard')->after('tax_rate')
                ->comment('standard=7%, exempt=0%, custom=user-defined');
        });

        // Set default tax_rate to 7 for all rows that still have the old 16 default
        \DB::table('product_pricing')
            ->where('tax_rate', 16)
            ->update(['tax_rate' => 7.00]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_pricing', function (Blueprint $table) {
            $table->dropColumn('tax_type');
        });
    }
};
