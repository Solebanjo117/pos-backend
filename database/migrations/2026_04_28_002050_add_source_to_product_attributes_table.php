<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->string('source')->default('custom')->after('unit');
            $table->unsignedBigInteger('source_category_id')->nullable()->after('source');
        });

        Schema::table('product_attributes', function (Blueprint $table) {
            $table->foreign('source_category_id')->references('id')->on('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->dropForeign(['source_category_id']);
            $table->dropColumn(['source', 'source_category_id']);
        });
    }
};
