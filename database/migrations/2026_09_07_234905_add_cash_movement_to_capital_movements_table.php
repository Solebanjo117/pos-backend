<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('capital_movements', function (Blueprint $table): void {
            $table->foreignId('cash_movement_id')->nullable()->after('source_id')->constrained('cash_movements')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capital_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cash_movement_id');
        });
    }
};
