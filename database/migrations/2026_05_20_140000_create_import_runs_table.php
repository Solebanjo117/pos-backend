<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity', 50);
            $table->unsignedBigInteger('import_profile_id')->nullable();
            $table->foreignId('parent_import_run_id')->nullable()->constrained('import_runs')->nullOnDelete();
            $table->string('original_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->unsignedInteger('sheet_index')->nullable();
            $table->string('sheet_name')->nullable();
            $table->string('run_type', 30)->default('import');
            $table->string('mode', 50)->default('create_update');
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('mapping')->nullable();
            $table->json('failed_rows')->nullable();
            $table->json('results')->nullable();
            $table->json('errors')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['entity', 'status']);
            $table->index('user_id');
            $table->index('import_profile_id');
            $table->index(['run_type', 'mode']);
        });

        Schema::create('import_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('entity', 50)->default('products');
            $table->json('mapping')->nullable();
            $table->unsignedInteger('sheet_index')->nullable();
            $table->string('sheet_name')->nullable();
            $table->string('mode', 50)->default('create_update');
            $table->json('grouping_rules')->nullable();
            $table->json('defaults')->nullable();
            $table->timestamps();

            $table->unique(['entity', 'user_id', 'name']);
        });

        Schema::create('import_run_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_run_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id')->nullable();
            $table->string('action', 30);
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $table->index(['import_run_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_run_records');
        Schema::dropIfExists('import_profiles');
        Schema::dropIfExists('import_runs');
    }
};
