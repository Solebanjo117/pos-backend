<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->foreignId('customer_vehicle_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('customer_vehicles')
                ->nullOnDelete();

            $table->index(['customer_id', 'customer_vehicle_id']);
            $table->index(['assigned_to', 'status']);
            $table->index('estimated_delivery_at');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'customer_vehicle_id']);
            $table->dropIndex(['assigned_to', 'status']);
            $table->dropIndex(['estimated_delivery_at']);
            $table->dropConstrainedForeignId('customer_vehicle_id');
        });
    }
};
