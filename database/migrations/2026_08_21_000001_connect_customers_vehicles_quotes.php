<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('normalized_name', 100)->unique();
            $table->string('base_kind', 20)->default('individual');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('customer_types')->insert([
            ['name' => 'Individual', 'normalized_name' => 'individual', 'base_kind' => 'individual', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Empresa', 'normalized_name' => 'empresa', 'base_kind' => 'business', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Gobierno', 'normalized_name' => 'gobierno', 'base_kind' => 'business', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Flota', 'normalized_name' => 'flota', 'base_kind' => 'business', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('customer_type_id')->nullable()->after('type')->constrained('customer_types')->restrictOnDelete();
        });

        $individualId = DB::table('customer_types')->where('normalized_name', 'individual')->value('id');
        $businessId = DB::table('customer_types')->where('normalized_name', 'empresa')->value('id');
        DB::table('customers')->where('type', 'individual')->update(['customer_type_id' => $individualId]);
        DB::table('customers')->where('type', 'business')->update(['customer_type_id' => $businessId]);

        Schema::table('customer_vehicles', function (Blueprint $table): void {
            $table->foreignId('vehicle_vin_id')->nullable()->after('vehicle_engine_id')->constrained('vehicle_vins')->restrictOnDelete();
            $table->unsignedSmallInteger('year')->nullable()->after('vin');
            $table->string('plate_number', 30)->nullable()->after('year');
            $table->index('plate_number');
        });

        DB::table('customer_vehicles')
            ->whereNotNull('vin')
            ->orderBy('id')
            ->eachById(function ($vehicle): void {
                $vehicleVinId = DB::table('vehicle_vins')
                    ->where('model_id', $vehicle->vehicle_model_id)
                    ->where('engine_id', $vehicle->vehicle_engine_id)
                    ->whereRaw('UPPER(vin) = ?', [Str::upper(trim((string) $vehicle->vin))])
                    ->value('id');

                if ($vehicleVinId) {
                    DB::table('customer_vehicles')->where('id', $vehicle->id)->update(['vehicle_vin_id' => $vehicleVinId]);
                }
            });

        Schema::table('sales_quotes', function (Blueprint $table): void {
            $table->foreignId('customer_vehicle_id')->nullable()->after('customer_id')->constrained('customer_vehicles')->nullOnDelete();
            $table->index(['customer_id', 'customer_vehicle_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'customer_vehicle_id']);
            $table->dropConstrainedForeignId('customer_vehicle_id');
        });

        Schema::table('customer_vehicles', function (Blueprint $table): void {
            $table->dropIndex(['plate_number']);
            $table->dropConstrainedForeignId('vehicle_vin_id');
            $table->dropColumn(['year', 'plate_number']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_type_id');
        });

        Schema::dropIfExists('customer_types');
    }
};
