<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add missing columns that the application expects but the original
     * migration didn't include: status, location, capacity, notes.
     */
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            if (!Schema::hasColumn('warehouses', 'status')) {
                $table->string('status', 20)->default('active')->after('name');
            }
            if (!Schema::hasColumn('warehouses', 'location')) {
                $table->text('location')->nullable()->after('status');
            }
            if (!Schema::hasColumn('warehouses', 'capacity')) {
                $table->integer('capacity')->nullable()->after('location');
            }
            if (!Schema::hasColumn('warehouses', 'notes')) {
                $table->text('notes')->nullable()->after('capacity');
            }
        });

        // Set all existing warehouses to 'active' status
        // (since the original is_active column defaults to true)
        DB::table('warehouses')
            ->whereNull('status')
            ->update(['status' => 'active']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $columns = ['status', 'location', 'capacity', 'notes'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('warehouses', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
