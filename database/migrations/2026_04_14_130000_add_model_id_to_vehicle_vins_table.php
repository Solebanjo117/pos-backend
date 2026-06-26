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
        Schema::table('vehicle_vins', function (Blueprint $table) {
            $table->foreignId('model_id')
                ->nullable()
                ->after('engine_id')
                ->constrained('vehicle_models')
                ->cascadeOnDelete();
        });

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('UPDATE vehicle_vins vv SET model_id = ve.model_id FROM vehicle_engines ve WHERE ve.id = vv.engine_id AND vv.model_id IS NULL');
            DB::statement('ALTER TABLE vehicle_vins ALTER COLUMN engine_id DROP NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('UPDATE vehicle_vins vv JOIN vehicle_engines ve ON ve.id = vv.engine_id SET vv.model_id = ve.model_id WHERE vv.model_id IS NULL');
            DB::statement('ALTER TABLE vehicle_vins MODIFY engine_id BIGINT UNSIGNED NULL');
        } else {
            DB::statement('UPDATE vehicle_vins SET model_id = (SELECT model_id FROM vehicle_engines WHERE vehicle_engines.id = vehicle_vins.engine_id) WHERE model_id IS NULL');

            // SQLite cannot alter an existing column nullability in place.
            // Recreate the table so engine_id can be nullable for model-scoped VINs.
            DB::statement('PRAGMA foreign_keys = OFF');

            try {
                DB::statement('CREATE TABLE vehicle_vins_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    engine_id INTEGER NULL,
                    model_id INTEGER NULL,
                    vin VARCHAR(17) NOT NULL,
                    notes VARCHAR(255) NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL,
                    FOREIGN KEY (engine_id) REFERENCES vehicle_engines(id) ON DELETE CASCADE,
                    FOREIGN KEY (model_id) REFERENCES vehicle_models(id) ON DELETE CASCADE
                )');

                DB::statement('INSERT INTO vehicle_vins_new (id, engine_id, model_id, vin, notes, created_at, updated_at)
                    SELECT id, engine_id, model_id, vin, notes, created_at, updated_at
                    FROM vehicle_vins');

                DB::statement('DROP TABLE vehicle_vins');
                DB::statement('ALTER TABLE vehicle_vins_new RENAME TO vehicle_vins');

                DB::statement('CREATE UNIQUE INDEX vehicle_vins_vin_unique ON vehicle_vins(vin)');
                DB::statement('CREATE INDEX vehicle_vins_engine_id_index ON vehicle_vins(engine_id)');
                DB::statement('CREATE INDEX vehicle_vins_model_id_index ON vehicle_vins(model_id)');
            } finally {
                DB::statement('PRAGMA foreign_keys = ON');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('UPDATE vehicle_vins vv SET engine_id = (SELECT ve.id FROM vehicle_engines ve WHERE ve.model_id = vv.model_id ORDER BY ve.id LIMIT 1) WHERE vv.engine_id IS NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('UPDATE vehicle_vins vv SET vv.engine_id = (SELECT ve.id FROM vehicle_engines ve WHERE ve.model_id = vv.model_id ORDER BY ve.id LIMIT 1) WHERE vv.engine_id IS NULL');
        } else {
            DB::statement('UPDATE vehicle_vins SET engine_id = (SELECT ve.id FROM vehicle_engines ve WHERE ve.model_id = vehicle_vins.model_id ORDER BY ve.id LIMIT 1) WHERE engine_id IS NULL');
        }

        DB::table('vehicle_vins')->whereNull('engine_id')->delete();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE vehicle_vins ALTER COLUMN engine_id SET NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE vehicle_vins MODIFY engine_id BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('vehicle_vins', function (Blueprint $table) {
            $table->dropForeign(['model_id']);
            $table->dropColumn('model_id');
        });
    }
};
