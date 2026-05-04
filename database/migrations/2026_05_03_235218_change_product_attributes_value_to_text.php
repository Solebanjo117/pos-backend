<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ampliar columna value de varchar(255) a text para soportar
     * JSON arrays en atributos de tipo select con multiselección.
     */
    public function up(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->text('value')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->string('value', 255)->change();
        });
    }
};
