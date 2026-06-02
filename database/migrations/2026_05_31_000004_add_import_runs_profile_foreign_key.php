<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_runs') || ! Schema::hasTable('import_profiles')) {
            return;
        }

        if (! Schema::hasColumn('import_runs', 'import_profile_id')) {
            Schema::table('import_runs', function (Blueprint $table): void {
                $table->foreignId('import_profile_id')
                    ->nullable()
                    ->after('entity')
                    ->constrained('import_profiles')
                    ->nullOnDelete();
            });

            return;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::table('import_runs')
            ->whereNotNull('import_profile_id')
            ->whereNotIn('import_profile_id', DB::table('import_profiles')->select('id'))
            ->update(['import_profile_id' => null]);

        Schema::table('import_runs', function (Blueprint $table): void {
            $table->foreign('import_profile_id')
                ->references('id')
                ->on('import_profiles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('import_runs') || ! Schema::hasColumn('import_runs', 'import_profile_id')) {
            return;
        }

        Schema::table('import_runs', function (Blueprint $table): void {
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['import_profile_id']);
            }

            $table->dropColumn('import_profile_id');
        });
    }
};
