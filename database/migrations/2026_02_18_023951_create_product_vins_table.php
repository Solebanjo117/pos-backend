<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_vins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('vin', 17);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'vin']);
            $table->index('vin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_vins');
    }
};
