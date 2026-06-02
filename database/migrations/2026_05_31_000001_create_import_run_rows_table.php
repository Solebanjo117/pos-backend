<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_run_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number')->default(0);
            $table->string('sheet_name')->nullable();
            $table->string('status', 30)->default('valid');
            $table->string('action_type', 30)->nullable();
            $table->string('label')->nullable();
            $table->json('data')->nullable();
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->json('conflicts')->nullable();
            $table->json('change_preview')->nullable();
            $table->timestamps();

            $table->index(['import_run_id', 'row_number']);
            $table->index(['import_run_id', 'status']);
            $table->index(['import_run_id', 'action_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_run_rows');
    }
};
