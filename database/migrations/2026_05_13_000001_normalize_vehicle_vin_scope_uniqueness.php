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

        $this->deduplicateScopedVins();
        $this->createScopedUniqueIndexes();
    }

    public function down(): void
    {
        if (! Schema::hasTable('vehicle_vins')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS vehicle_vins_model_engine_vin_null_chassis_unique');
            DB::statement('DROP INDEX IF EXISTS vehicle_vins_model_chassis_engine_vin_not_null_unique');
        }
    }

    private function deduplicateScopedVins(): void
    {
        $seen = [];
        $deleteIds = [];

        DB::table('vehicle_vins')
            ->select(['id', 'model_id', 'chassis_id', 'engine_id', 'vin'])
            ->orderBy('id')
            ->chunkById(500, function ($vins) use (&$seen, &$deleteIds): void {
                foreach ($vins as $vin) {
                    $key = implode('|', [
                        $vin->model_id ?? 'null',
                        $vin->chassis_id ?? 'null',
                        $vin->engine_id ?? 'null',
                        mb_strtoupper((string) $vin->vin),
                    ]);

                    if (isset($seen[$key])) {
                        $deleteIds[] = $vin->id;

                        continue;
                    }

                    $seen[$key] = true;
                }
            });

        collect($deleteIds)
            ->chunk(500)
            ->each(fn ($ids) => DB::table('vehicle_vins')->whereIn('id', $ids)->delete());
    }

    private function createScopedUniqueIndexes(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS vehicle_vins_model_engine_vin_null_chassis_unique ON vehicle_vins (model_id, engine_id, vin) WHERE chassis_id IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS vehicle_vins_model_chassis_engine_vin_not_null_unique ON vehicle_vins (model_id, chassis_id, engine_id, vin) WHERE chassis_id IS NOT NULL'
        );
    }
};
