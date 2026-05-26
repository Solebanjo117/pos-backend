<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('vehicle_model_id')->constrained('vehicle_models')->restrictOnDelete();
            $table->foreignId('vehicle_chassis_id')->nullable()->constrained('vehicle_chassis')->restrictOnDelete();
            $table->foreignId('vehicle_engine_id')->constrained('vehicle_engines')->restrictOnDelete();
            $table->string('vin', 40);
            $table->string('label')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('vin');
            $table->unique(['customer_id', 'vin']);
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignId('customer_vehicle_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('customer_vehicles')
                ->nullOnDelete();
            $table->foreignId('referred_by_customer_id')
                ->nullable()
                ->after('customer_vehicle_id')
                ->constrained('customers')
                ->nullOnDelete();

            $table->index('referred_by_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_vehicle_id');
            $table->dropConstrainedForeignId('referred_by_customer_id');
        });

        Schema::dropIfExists('customer_vehicles');
    }
};
