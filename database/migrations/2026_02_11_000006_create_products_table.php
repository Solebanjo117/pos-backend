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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->string('upc', 20)->nullable()->index();
            $table->foreignId('brand_id')->constrained()->onDelete('restrict');
            $table->foreignId('product_line_id')->nullable()->constrained()->onDelete('set null');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'discontinued', 'pending'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('brand_id');
            $table->index(['brand_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
