<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vehicle_engines', 'make_id')) {
            Schema::table('vehicle_engines', function (Blueprint $table): void {
                $table->foreignId('make_id')
                    ->nullable()
                    ->after('model_id')
                    ->constrained('vehicle_makes')
                    ->nullOnDelete();
            });
        }

        DB::table('vehicle_engines')
            ->whereNull('make_id')
            ->whereNotNull('model_id')
            ->orderBy('id')
            ->chunkById(200, function ($engines): void {
                foreach ($engines as $engine) {
                    $makeId = DB::table('vehicle_models')
                        ->where('id', $engine->model_id)
                        ->value('make_id');

                    if ($makeId) {
                        DB::table('vehicle_engines')
                            ->where('id', $engine->id)
                            ->update(['make_id' => $makeId]);
                    }
                }
            });

        DB::table('vehicle_engines')
            ->whereNull('make_id')
            ->orderBy('id')
            ->chunkById(200, function ($engines): void {
                foreach ($engines as $engine) {
                    $modelId = DB::table('vehicle_engine_model')
                        ->where('engine_id', $engine->id)
                        ->orderBy('model_id')
                        ->value('model_id');

                    if (! $modelId) {
                        continue;
                    }

                    $makeId = DB::table('vehicle_models')
                        ->where('id', $modelId)
                        ->value('make_id');

                    if ($makeId) {
                        DB::table('vehicle_engines')
                            ->where('id', $engine->id)
                            ->update(['make_id' => $makeId]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('vehicle_engines', 'make_id')) {
            Schema::table('vehicle_engines', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('make_id');
            });
        }
    }
};
