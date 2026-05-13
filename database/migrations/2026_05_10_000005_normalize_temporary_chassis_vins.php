<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $temporaryChassisIds = DB::table('vehicle_chassis')
            ->whereRaw('LOWER(code) = ?', ['sin_codigo'])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($temporaryChassisIds === []) {
            return;
        }

        DB::table('vehicle_vins')
            ->select(['id', 'model_id', 'engine_id', 'vin'])
            ->whereIn('chassis_id', $temporaryChassisIds)
            ->orderBy('id')
            ->chunkById(100, function ($vins): void {
                foreach ($vins as $vin) {
                    $duplicate = DB::table('vehicle_vins')
                        ->where('model_id', $vin->model_id)
                        ->where('vin', $vin->vin)
                        ->where('id', '<>', $vin->id)
                        ->whereNull('chassis_id')
                        ->when(
                            $vin->engine_id === null,
                            static fn ($query) => $query->whereNull('engine_id'),
                            static fn ($query) => $query->where('engine_id', $vin->engine_id)
                        )
                        ->first();

                    if ($duplicate) {
                        DB::table('vehicle_vins')->where('id', $vin->id)->delete();

                        continue;
                    }

                    DB::table('vehicle_vins')
                        ->where('id', $vin->id)
                        ->update(['chassis_id' => null]);
                }
            });
    }

    public function down(): void {}
};
