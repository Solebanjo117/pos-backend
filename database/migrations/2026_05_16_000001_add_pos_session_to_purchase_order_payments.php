<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_payments', function (Blueprint $table): void {
            $table->foreignId('pos_session_id')
                ->nullable()
                ->after('purchase_order_id')
                ->constrained('pos_sessions')
                ->nullOnDelete();

            $table->foreignId('cash_movement_id')
                ->nullable()
                ->after('pos_session_id')
                ->constrained('cash_movements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cash_movement_id');
            $table->dropConstrainedForeignId('pos_session_id');
        });
    }
};
