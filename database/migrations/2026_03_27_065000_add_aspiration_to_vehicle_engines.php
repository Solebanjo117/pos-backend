<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add aspiration column to vehicle_engines table
 * Legacy field for compatibility
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_engines', function (Blueprint $table) {
            $table->string('aspiration', 50)->nullable()->after('fuel_type');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_engines', function (Blueprint $table) {
            $table->dropColumn('aspiration');
        });
    }
};
