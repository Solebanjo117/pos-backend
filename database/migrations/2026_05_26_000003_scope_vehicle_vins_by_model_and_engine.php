<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_vins')) {
            return;
        }

        $this->dropScopedVinIndexes();
        $this->deduplicateModelEngineVins();

        DB::table('vehicle_vins')->update(['chassis_id' => null]);

        $this->createModelEngineVinIndex();
    }

    public function down(): void
    {
        if (! Schema::hasTable('vehicle_vins')) {
            return;
        }

        $this->dropModelEngineVinIndex();
    }

    private function deduplicateModelEngineVins(): void
    {
        $seen = [];
        $deleteIds = [];

        DB::table('vehicle_vins')
            ->select(['id', 'model_id', 'engine_id', 'vin'])
            ->orderBy('id')
            ->chunkById(500, function ($vins) use (&$seen, &$deleteIds): void {
                foreach ($vins as $vin) {
                    $normalizedVin = mb_strtoupper(trim((string) $vin->vin));
                    $key = implode('|', [
                        $vin->model_id ?? 'null',
                        $vin->engine_id ?? 'null',
                        $normalizedVin,
                    ]);

                    if (isset($seen[$key])) {
                        $deleteIds[] = $vin->id;

                        continue;
                    }

                    $seen[$key] = true;

                    if ($vin->vin !== $normalizedVin) {
                        DB::table('vehicle_vins')
                            ->where('id', $vin->id)
                            ->update(['vin' => $normalizedVin]);
                    }
                }
            });

        collect($deleteIds)
            ->chunk(500)
            ->each(fn ($ids) => DB::table('vehicle_vins')->whereIn('id', $ids)->delete());
    }

    private function dropScopedVinIndexes(): void
    {
        try {
            Schema::table('vehicle_vins', function ($table): void {
                $table->dropUnique('vehicle_vins_model_chassis_engine_vin_unique');
            });
        } catch (Throwable) {
            //
        }

        DB::statement('DROP INDEX IF EXISTS vehicle_vins_model_engine_vin_unique');
        DB::statement('DROP INDEX IF EXISTS vehicle_vins_model_engine_vin_null_chassis_unique');
        DB::statement('DROP INDEX IF EXISTS vehicle_vins_model_chassis_engine_vin_not_null_unique');
        DB::statement('DROP INDEX IF EXISTS vehicle_vins_model_chassis_engine_vin_unique');
    }

    private function createModelEngineVinIndex(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS vehicle_vins_model_engine_vin_unique ON vehicle_vins (model_id, engine_id, vin)'
        );
    }

    private function dropModelEngineVinIndex(): void
    {
        DB::statement('DROP INDEX IF EXISTS vehicle_vins_model_engine_vin_unique');
    }
};
