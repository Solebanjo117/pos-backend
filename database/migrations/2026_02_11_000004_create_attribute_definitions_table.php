<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attribute_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->enum('data_type', ['decimal', 'integer', 'string', 'boolean'])->default('string');
            $table->string('unit', 20)->nullable()->comment('V, A, mm, kg, etc.');
            $table->json('applicable_categories')->nullable()->comment('Array of category IDs');
            $table->boolean('is_required')->default(false);
            $table->timestamps();

            $table->index('data_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attribute_definitions');
    }
};
