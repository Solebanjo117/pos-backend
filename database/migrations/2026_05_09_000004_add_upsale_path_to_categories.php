<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'upsale_path')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->string('upsale_path')->nullable()->after('min_suggested_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories', 'upsale_path')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->dropColumn('upsale_path');
            });
        }
    }
};
