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
        Schema::table('product_suppliers', function (Blueprint $table) {
            // Add variant_id column
            $table->foreignId('variant_id')->nullable()->after('id')->constrained('product_variants')->onDelete('cascade');

            // Make product_id nullable since we're transitioning to variant_id
            $table->foreignId('product_id')->nullable()->change();

            // Add index for better performance
            $table->index('variant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_suppliers', function (Blueprint $table) {
            $table->dropForeign(['variant_id']);
            $table->dropIndex(['variant_id']);
            $table->dropColumn('variant_id');

            // Restore product_id as not nullable
            $table->foreignId('product_id')->nullable(false)->change();
        });
    }
};
