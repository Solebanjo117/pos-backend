<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vehicle_vins', 'chassis_id')) {
            Schema::table('vehicle_vins', function (Blueprint $table): void {
                $table->foreignId('chassis_id')
                    ->nullable()
                    ->after('model_id')
                    ->constrained('vehicle_chassis')
                    ->nullOnDelete();
            });
        }

        $this->backfillChassisFromModelGeneration();

        try {
            Schema::table('vehicle_vins', function (Blueprint $table): void {
                $table->dropUnique('vehicle_vins_vin_unique');
            });
        } catch (Throwable) {
            // Some local/test schemas may already be on the scoped VIN contract.
        }

        try {
            Schema::table('vehicle_vins', function (Blueprint $table): void {
                $table->unique(
                    ['model_id', 'chassis_id', 'engine_id', 'vin'],
                    'vehicle_vins_model_chassis_engine_vin_unique'
                );
            });
        } catch (Throwable) {
            // Avoid failing deployments where the index already exists.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('vehicle_vins', function (Blueprint $table): void {
                $table->dropUnique('vehicle_vins_model_chassis_engine_vin_unique');
            });
        } catch (Throwable) {
            //
        }

        try {
            Schema::table('vehicle_vins', function (Blueprint $table): void {
                $table->unique('vin', 'vehicle_vins_vin_unique');
            });
        } catch (Throwable) {
            //
        }

        if (Schema::hasColumn('vehicle_vins', 'chassis_id')) {
            Schema::table('vehicle_vins', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('chassis_id');
            });
        }
    }

    private function backfillChassisFromModelGeneration(): void
    {
        DB::table('vehicle_vins')
            ->whereNull('chassis_id')
            ->whereNotNull('model_id')
            ->orderBy('id')
            ->chunkById(100, function ($vins): void {
                foreach ($vins as $vin) {
                    $model = DB::table('vehicle_models')->where('id', $vin->model_id)->first();
                    if (! $model || empty($model->generation)) {
                        continue;
                    }

                    $chassisId = DB::table('vehicle_chassis')
                        ->where('model_id', $vin->model_id)
                        ->whereRaw('LOWER(code) = ?', [mb_strtolower((string) $model->generation)])
                        ->value('id');

                    if ($chassisId) {
                        DB::table('vehicle_vins')
                            ->where('id', $vin->id)
                            ->update(['chassis_id' => $chassisId]);
                    }
                }
            });
    }
};
