<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds warehouse_id to pos_sessions so that each session is scoped
     * to a specific branch/warehouse, preventing cross-branch sales mixing.
     */
    public function up(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('user_id')
                ->constrained('warehouses')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });
    }
};
