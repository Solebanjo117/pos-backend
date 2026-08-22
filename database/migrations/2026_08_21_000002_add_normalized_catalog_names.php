<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('normalized_name')->nullable()->after('name');
        });
        Schema::table('brands', function (Blueprint $table): void {
            $table->string('normalized_name')->nullable()->after('name');
        });
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('normalized_name')->nullable()->after('name');
        });

        $this->backfill('suppliers');
        $this->backfill('brands');
        $this->backfill('categories');

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->unique('normalized_name', 'suppliers_normalized_name_unique');
        });
        Schema::table('brands', function (Blueprint $table): void {
            $table->unique('normalized_name', 'brands_normalized_name_unique');
        });
        Schema::table('categories', function (Blueprint $table): void {
            $table->index(['parent_id', 'normalized_name'], 'categories_parent_normalized_index');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex('categories_parent_normalized_index');
            $table->dropColumn('normalized_name');
        });
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropUnique('brands_normalized_name_unique');
            $table->dropColumn('normalized_name');
        });
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropUnique('suppliers_normalized_name_unique');
            $table->dropColumn('normalized_name');
        });
    }

    private function backfill(string $table): void
    {
        DB::table($table)->orderBy('id')->eachById(function (object $row) use ($table): void {
            DB::table($table)->where('id', $row->id)->update([
                'normalized_name' => $this->normalize((string) $row->name),
            ]);
        });
    }

    private function normalize(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }
};
