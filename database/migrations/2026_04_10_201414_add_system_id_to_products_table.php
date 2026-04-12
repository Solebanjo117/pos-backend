<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates system_sequences table and adds system_id to products with backfill.
     */
    public function up(): void
    {
        // 1. Create system_sequences table for atomic ID generation
        Schema::create('system_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->bigInteger('next_value')->default(1);
            $table->timestamps();
        });

        // 2. Add system_id column to products (nullable initially for backfill)
        Schema::table('products', function (Blueprint $table) {
            $table->string('system_id', 10)->nullable()->after('id');
        });

        // 3. Backfill existing products with sequential system_ids
        $products = DB::table('products')->orderBy('id')->get();
        $counter = 1;
        foreach ($products as $product) {
            DB::table('products')
                ->where('id', $product->id)
                ->update(['system_id' => str_pad($counter, 8, '0', STR_PAD_LEFT)]);
            $counter++;
        }

        // 4. Seed the sequence with the next available value
        DB::table('system_sequences')->insert([
            'key' => 'product_system_id',
            'next_value' => $counter,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5. Make system_id non-nullable and add unique index
        Schema::table('products', function (Blueprint $table) {
            $table->string('system_id', 10)->nullable(false)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('system_id');
        });

        Schema::dropIfExists('system_sequences');
    }
};
