<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vehicle_engines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_id')->constrained('vehicle_models')->onDelete('cascade');
            $table->string('engine_code', 50);
            $table->string('displacement', 20)->nullable()->comment('e.g., 3.5L, 2000cc');
            $table->string('fuel_type', 50)->nullable()->comment('Gasoline, Diesel, Hybrid, etc.');
            $table->timestamps();
            
            $table->index(['model_id', 'engine_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_engines');
    }
};
