<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add alternate_code (secondary reference code) to products table.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('alternate_code', 100)->nullable()->after('sku_base');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('alternate_code');
        });
    }
};
