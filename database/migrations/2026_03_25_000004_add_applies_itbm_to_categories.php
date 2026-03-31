<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add applies_itbm to categories table.
     * When true, 7% ITBM is applied OVER the result of pricing_formula (not over cost).
     * Example: formula "costo * 1.3" + ITBM → price = (cost * 1.3) * 1.07
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('applies_itbm')->default(false)->after('pricing_formula');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('applies_itbm');
        });
    }
};
