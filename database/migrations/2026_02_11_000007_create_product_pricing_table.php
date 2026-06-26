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
        Schema::create('product_pricing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->onDelete('cascade');
            $table->decimal('base_price', 10, 2)->default(0);
            $table->decimal('msrp', 10, 2)->default(0)->comment('Manufacturer Suggested Retail Price');
            $table->decimal('cost', 10, 2)->default(0);
            $table->decimal('margin_percentage', 5, 2)->default(0)->comment('Calculated margin %');
            $table->decimal('tax_rate', 5, 2)->default(0)->comment('Tax percentage');
            $table->decimal('max_discount_percentage', 5, 2)->default(0)->comment('Maximum allowed discount');
            $table->timestamps();

            $table->index('base_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_pricing');
    }
};
