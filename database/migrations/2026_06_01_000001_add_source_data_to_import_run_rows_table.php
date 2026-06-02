<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_run_rows', function (Blueprint $table): void {
            $table->json('source_data')->nullable()->after('data');
        });
    }

    public function down(): void
    {
        Schema::table('import_run_rows', function (Blueprint $table): void {
            $table->dropColumn('source_data');
        });
    }
};
