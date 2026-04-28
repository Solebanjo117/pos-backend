<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('media_type', 50)->default('image');
            $table->string('category', 100)->default('photo');
            $table->string('file_path', 500);
            $table->string('original_filename', 255)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->index(['category_id', 'media_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_media');
    }
};
