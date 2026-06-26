<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_vins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engine_id')->constrained('vehicle_engines')->onDelete('cascade');
            $table->string('vin', 17)->comment('17-character Vehicle Identification Number');
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique('vin');
            $table->index('engine_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_vins');
    }
};
