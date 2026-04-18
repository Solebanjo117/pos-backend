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
        if (!Schema::hasTable('vehicle_vins') || !Schema::hasColumn('vehicle_vins', 'vin')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite cannot alter VARCHAR length in place, so recreate the table.
            DB::statement('PRAGMA foreign_keys = OFF');

            try {
                DB::statement('CREATE TABLE vehicle_vins_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    engine_id INTEGER NULL,
                    model_id INTEGER NULL,
                    vin VARCHAR(40) NOT NULL,
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

            return;
        }

        Schema::table('vehicle_vins', function (Blueprint $table) {
            $table->string('vin', 40)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('vehicle_vins') || !Schema::hasColumn('vehicle_vins', 'vin')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
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

            return;
        }

        Schema::table('vehicle_vins', function (Blueprint $table) {
            $table->string('vin', 17)->change();
        });
    }
};
