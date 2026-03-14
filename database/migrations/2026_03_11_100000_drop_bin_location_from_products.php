<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the bin_location column from products table.
     * bin_location was mistakenly added — VINs are tracked in the product_vins table.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'bin_location')) {
                $table->dropColumn('bin_location');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('bin_location', 50)->nullable()->after('alternate_code');
        });
    }
};
