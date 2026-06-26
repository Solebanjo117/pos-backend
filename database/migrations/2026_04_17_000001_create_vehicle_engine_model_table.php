<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vehicle_engine_model', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engine_id')
                ->constrained('vehicle_engines')
                ->cascadeOnDelete();
            $table->foreignId('model_id')
                ->constrained('vehicle_models')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['engine_id', 'model_id']);
            $table->index('model_id');
        });

        $now = now();
        $legacyAssociations = DB::table('vehicle_engines')
            ->whereNotNull('model_id')
            ->select('id as engine_id', 'model_id')
            ->get();

        if ($legacyAssociations->isNotEmpty()) {
            $rows = $legacyAssociations
                ->map(fn ($row) => [
                    'engine_id' => (int) $row->engine_id,
                    'model_id' => (int) $row->model_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            DB::table('vehicle_engine_model')->insert($rows);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_engine_model');
    }
};
