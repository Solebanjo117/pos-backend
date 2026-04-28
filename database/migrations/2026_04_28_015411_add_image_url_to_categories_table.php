<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'image_url')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string('image_url', 500)->nullable()->after('icon');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories', 'image_url')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('image_url');
            });
        }
    }
};
