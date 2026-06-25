<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R21: Make engines independent entities
 * model_id becomes nullable so engines can exist without being tied to a specific model
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_engines', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['model_id']);

            // Make the column nullable
            $table->foreignId('model_id')
                ->nullable()
                ->change();

            // Re-add the foreign key with nullable support
            $table->foreign('model_id')
                ->references('id')
                ->on('vehicle_models')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_engines', function (Blueprint $table) {
            $table->dropForeign(['model_id']);

            $table->foreignId('model_id')
                ->nullable(false)
                ->change();

            $table->foreign('model_id')
                ->references('id')
                ->on('vehicle_models')
                ->cascadeOnDelete();
        });
    }
};
