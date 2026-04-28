<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('brands', 'prefix')) {
            return;
        }

        try {
            Schema::table('brands', function (Blueprint $table) {
                $table->dropUnique(['prefix']);
            });
        } catch (\Throwable) {
            // Unique constraint may not exist
        }

        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('prefix');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('brands', 'prefix')) {
            return;
        }

        Schema::table('brands', function (Blueprint $table) {
            $table->string('prefix', 5)->nullable()->after('name');
            $table->unique(['prefix']);
        });
    }
};
