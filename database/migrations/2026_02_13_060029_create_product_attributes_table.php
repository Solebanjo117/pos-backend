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
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name')->index(); // e.g: "Voltage", "Diameter"
            $table->string('value')->index(); // e.g: "12V", "50mm"
            $table->string('unit')->nullable(); // e.g: "V", "mm" (Separating unit allows better conversion later)
            $table->timestamps();

            // Compound index for quick lookups of a specific attribute value
            $table->index(['name', 'value']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
    }
};
