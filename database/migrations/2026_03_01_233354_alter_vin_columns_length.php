<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_vins', function (Blueprint $table) {
            $table->string('vin', 40)->change();
        });

        Schema::table('vehicle_vins', function (Blueprint $table) {
            $table->string('vin', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_vins', function (Blueprint $table) {
            $table->string('vin', 17)->change();
        });

        Schema::table('vehicle_vins', function (Blueprint $table) {
            $table->string('vin', 17)->change();
        });
    }
};
