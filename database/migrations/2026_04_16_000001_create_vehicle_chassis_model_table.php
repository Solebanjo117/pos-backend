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
        Schema::create('vehicle_chassis_model', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chassis_id')
                ->constrained('vehicle_chassis')
                ->cascadeOnDelete();
            $table->foreignId('model_id')
                ->constrained('vehicle_models')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['chassis_id', 'model_id']);
            $table->index('model_id');
        });

        $now = now();
        $legacyAssociations = DB::table('vehicle_chassis')
            ->whereNotNull('model_id')
            ->select('id as chassis_id', 'model_id')
            ->get();

        if ($legacyAssociations->isNotEmpty()) {
            $rows = $legacyAssociations
                ->map(fn ($row) => [
                    'chassis_id' => (int) $row->chassis_id,
                    'model_id' => (int) $row->model_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            DB::table('vehicle_chassis_model')->insert($rows);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_chassis_model');
    }
};
