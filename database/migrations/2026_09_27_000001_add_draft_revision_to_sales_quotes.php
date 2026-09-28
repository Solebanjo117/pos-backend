<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table): void {
            $table->unsignedInteger('draft_revision')->default(0);
            $table->string('draft_request_hash', 64)->nullable();
            $table->json('vehicle_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table): void {
            $table->dropColumn('draft_revision');
            $table->dropColumn('draft_request_hash');
            $table->dropColumn('vehicle_context');
        });
    }
};
