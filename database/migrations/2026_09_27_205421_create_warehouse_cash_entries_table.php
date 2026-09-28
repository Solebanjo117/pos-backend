<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_cash_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('source_line_key')->default('main');
            $table->decimal('amount', 14, 2);
            $table->timestamp('occurred_at')->index();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'source_line_key'], 'warehouse_cash_unique_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_cash_entries');
    }
};
