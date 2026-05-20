<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_expenses', function (Blueprint $table): void {
            $table->foreignId('pos_session_id')
                ->nullable()
                ->after('cash_movement_id')
                ->constrained('pos_sessions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('operational_expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pos_session_id');
        });
    }
};
