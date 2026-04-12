<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string, boolean, number, json
            $table->string('group')->default('general'); // general, inventory, sales, purchases
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Insert default settings
        DB::table('settings')->insert([
            [
                'key' => 'allow_negative_inventory',
                'value' => 'false',
                'type' => 'boolean',
                'group' => 'inventory',
                'description' => 'Permitir ventas con stock negativo (Backorders)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'negative_inventory_warning',
                'value' => 'true',
                'type' => 'boolean',
                'group' => 'inventory',
                'description' => 'Mostrar advertencia al vender productos sin stock',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
