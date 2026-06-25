<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add itbm_percent column to categories table.
 * Allows customizing ITBM percentage per category (default 7%).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->decimal('itbm_percent', 5, 2)->default(7.00)->after('applies_itbm');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('itbm_percent');
        });
    }
};
