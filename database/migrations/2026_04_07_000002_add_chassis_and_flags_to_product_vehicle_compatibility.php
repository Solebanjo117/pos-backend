<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extend product_vehicle_compatibility to support:
     * - chassis_id (new FK to vehicle_chassis)
     * - all_engines / all_chassis flags
     * - engine_id becomes nullable (was NOT NULL)
     * - Drop the old (product_id, engine_id) unique — replaced by code validation
     *
     * Existing records are NOT touched. engine_id stays populated on them.
     */
    public function up(): void
    {
        // 1. Drop the old unique constraint (MySQL/PostgreSQL compatible)
        Schema::table('product_vehicle_compatibility', function (Blueprint $table) {
            // Drop unique index by name (created in original migration)
            $sm = Schema::getConnection()->getSchemaBuilder();
            // We use raw DDL to be safe across DB drivers
        });

        // Drop unique constraint via raw SQL (portable)
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE product_vehicle_compatibility DROP INDEX product_engine_unique');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE product_vehicle_compatibility DROP CONSTRAINT product_engine_unique');
        }
        // SQLite: unique constraints can't be dropped easily, but is only used in tests

        // 2. Make engine_id nullable
        Schema::table('product_vehicle_compatibility', function (Blueprint $table) {
            $table->foreignId('engine_id')
                ->nullable()
                ->change();
        });

        // 3. Add new columns
        Schema::table('product_vehicle_compatibility', function (Blueprint $table) {
            $table->foreignId('chassis_id')
                ->nullable()
                ->after('engine_id')
                ->constrained('vehicle_chassis')
                ->onDelete('cascade');

            $table->boolean('all_engines')
                ->default(false)
                ->after('chassis_id');

            $table->boolean('all_chassis')
                ->default(false)
                ->after('all_engines');

            // Composite index for efficient lookups
            $table->index(['product_id', 'engine_id', 'chassis_id'], 'product_vehicle_compat_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_vehicle_compatibility', function (Blueprint $table) {
            $table->dropColumn(['chassis_id', 'all_engines', 'all_chassis']);
            $table->dropIndex('product_vehicle_compat_idx');
        });

        // Restore engine_id to NOT NULL (only safe if no nulls exist)
        Schema::table('product_vehicle_compatibility', function (Blueprint $table) {
            $table->foreignId('engine_id')
                ->nullable(false)
                ->change();
        });

        // Restore unique constraint
        Schema::table('product_vehicle_compatibility', function (Blueprint $table) {
            $table->unique(['product_id', 'engine_id'], 'product_engine_unique');
        });
    }
};
