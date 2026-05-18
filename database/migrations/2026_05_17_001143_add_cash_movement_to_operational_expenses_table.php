<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_expenses', function (Blueprint $table): void {
            $table->foreignId('cash_movement_id')
                ->nullable()
                ->after('created_by')
                ->constrained('cash_movements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('operational_expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cash_movement_id');
        });
    }
};
