<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_vehicle_compatibility', function (Blueprint $table): void {
            $table->string('raw_vehicle_key', 255)->nullable()->after('chassis_id');
            $table->text('raw_vehicle_text')->nullable()->after('raw_vehicle_key');
            $table->index(['product_id', 'raw_vehicle_key'], 'product_vehicle_raw_key_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_vehicle_compatibility', function (Blueprint $table): void {
            $table->dropIndex('product_vehicle_raw_key_idx');
            $table->dropColumn(['raw_vehicle_key', 'raw_vehicle_text']);
        });
    }
};
