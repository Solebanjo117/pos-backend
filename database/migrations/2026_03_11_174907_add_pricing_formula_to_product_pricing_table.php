<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_pricing', function (Blueprint $table) {
            $table->string('pricing_formula', 255)->nullable()->after('max_discount_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('product_pricing', function (Blueprint $table) {
            $table->dropColumn('pricing_formula');
        });
    }
};
