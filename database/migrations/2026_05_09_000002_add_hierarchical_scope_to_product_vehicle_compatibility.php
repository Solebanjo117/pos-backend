<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_vehicle_compatibility', function (Blueprint $table): void {
            $table->string('scope_type', 20)->default('engine')->after('product_id');
            $table->foreignId('make_id')->nullable()->after('scope_type')->constrained('vehicle_makes')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->after('make_id')->constrained('vehicle_models')->nullOnDelete();
        });

        DB::table('product_vehicle_compatibility')
            ->where('all_engines', true)
            ->orWhere('all_chassis', true)
            ->update(['scope_type' => 'universal']);

        DB::table('product_vehicle_compatibility')
            ->whereNotNull('chassis_id')
            ->update(['scope_type' => 'chassis']);

        DB::table('product_vehicle_compatibility')
            ->whereNotNull('engine_id')
            ->update(['scope_type' => 'engine']);
    }

    public function down(): void
    {
        Schema::table('product_vehicle_compatibility', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('model_id');
            $table->dropConstrainedForeignId('make_id');
            $table->dropColumn('scope_type');
        });
    }
};
