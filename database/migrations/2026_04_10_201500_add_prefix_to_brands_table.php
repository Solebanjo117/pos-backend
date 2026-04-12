<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'prefix' column to brands and create 'brand_sequences' table.
     * Auto-generates 3-letter prefixes from existing brand names.
     */
    public function up(): void
    {
        // 1. Add prefix column to brands
        Schema::table('brands', function (Blueprint $table) {
            $table->string('prefix', 5)->nullable()->after('name');
        });

        // 2. Backfill: generate 3-letter prefix from brand name
        $brands = DB::table('brands')->orderBy('id')->get();
        $usedPrefixes = [];

        foreach ($brands as $brand) {
            $name = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $brand->name));
            $prefix = substr($name, 0, 3);

            // Ensure uniqueness by appending digits if needed
            $basePrefix = $prefix;
            $suffix = 1;
            while (in_array($prefix, $usedPrefixes)) {
                $prefix = substr($basePrefix, 0, 2) . $suffix;
                $suffix++;
            }
            $usedPrefixes[] = $prefix;

            DB::table('brands')
                ->where('id', $brand->id)
                ->update(['prefix' => $prefix]);
        }

        // 3. Add unique index after backfill
        Schema::table('brands', function (Blueprint $table) {
            $table->unique('prefix');
        });

        // 4. Create brand_sequences table
        Schema::create('brand_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->onDelete('cascade');
            $table->bigInteger('next_value')->default(1);
            $table->timestamps();
            $table->unique('brand_id');
        });

        // 5. Seed brand_sequences for all existing brands
        foreach ($brands as $brand) {
            DB::table('brand_sequences')->insert([
                'brand_id' => $brand->id,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_sequences');

        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('prefix');
        });
    }
};
