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
        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->enum('media_type', ['image', 'pdf', 'video'])->default('image');
            $table->enum('category', [
                'technical_sheet',
                'diagram',
                'manual',
                'certificate',
                'photo',
            ])->default('photo');
            $table->string('file_path');
            $table->string('original_filename');
            $table->unsignedBigInteger('file_size')->default(0)->comment('File size in bytes');
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'media_type']);
            $table->index(['product_id', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_media');
    }
};
