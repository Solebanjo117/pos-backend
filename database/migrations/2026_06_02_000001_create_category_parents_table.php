<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_parents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('parent_id')->constrained('categories')->cascadeOnDelete();
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'parent_id'], 'category_parents_unique');
            $table->index(['parent_id', 'priority'], 'category_parents_parent_priority_index');
        });

        $now = now();

        DB::table('categories')
            ->whereNotNull('parent_id')
            ->orderBy('id')
            ->chunkById(200, function ($categories) use ($now): void {
                $rows = $categories->map(fn ($category): array => [
                    'category_id' => $category->id,
                    'parent_id' => $category->parent_id,
                    'priority' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('category_parents')->insertOrIgnore($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_parents');
    }
};
