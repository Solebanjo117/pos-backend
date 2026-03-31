<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add default_margin_percent to categories.
     * Used to auto-calculate sale price when creating products in this category.
     * Example: 50.00 = 50% margin over cost → price = cost * 1.50
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->decimal('default_margin_percent', 5, 2)->nullable()->after('pricing_formula');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('default_margin_percent');
        });
    }
};
