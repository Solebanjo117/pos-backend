<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_warehouse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['user_id', 'warehouse_id']);
        });

        // Seed: assign all existing users to all existing warehouses
        $users = \App\Models\User::all();
        $warehouses = \App\Models\Warehouse::all();
        foreach ($users as $user) {
            foreach ($warehouses as $warehouse) {
                \DB::table('user_warehouse')->insert([
                    'user_id' => $user->id,
                    'warehouse_id' => $warehouse->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_warehouse');
    }
};
