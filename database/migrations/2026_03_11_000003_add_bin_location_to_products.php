<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add bin_location (warehouse shelf/bin position) to products table.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('bin_location', 50)->nullable()->after('alternate_code');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('bin_location');
        });
    }
};
