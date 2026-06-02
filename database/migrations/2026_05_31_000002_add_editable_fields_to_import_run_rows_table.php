<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_run_rows', function (Blueprint $table): void {
            $table->text('search_text')->nullable()->after('label');
            $table->boolean('is_edited')->default(false)->after('search_text');
            $table->timestamp('validated_at')->nullable()->after('is_edited');
            $table->unique(['import_run_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::table('import_run_rows', function (Blueprint $table): void {
            $table->dropUnique(['import_run_id', 'row_number']);
            $table->dropColumn(['search_text', 'is_edited', 'validated_at']);
        });
    }
};
