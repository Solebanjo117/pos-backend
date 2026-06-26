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
        Schema::create('vehicle_chassis', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50);             // Ej: "P90", "E90", "ZN6"
            $table->string('name')->nullable();      // Ej: "Serie P90"
            // R21-equivalent: model_id is optional — chassis can be independent
            $table->foreignId('model_id')
                ->nullable()
                ->constrained('vehicle_models')
                ->onDelete('set null');
            $table->string('year_from', 4)->nullable();
            $table->string('year_to', 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['model_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_chassis');
    }
};
