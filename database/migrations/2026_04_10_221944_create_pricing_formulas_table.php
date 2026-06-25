<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_formulas', function (Blueprint $table) {
            $table->id();
            $table->string('name');                      // Human-readable label e.g. "Costo × 2"
            $table->string('formula');                   // The formula string e.g. "costo * 2"
            $table->text('description')->nullable();     // Optional explanation
            $table->boolean('is_preset')->default(false); // Marks built-in presets (cannot be deleted)
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_formulas');
    }
};
