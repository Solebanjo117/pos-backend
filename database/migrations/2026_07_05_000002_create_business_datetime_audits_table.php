<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_datetime_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('record_type', 80);
            $table->unsignedBigInteger('record_id');
            $table->dateTime('old_business_datetime')->nullable();
            $table->dateTime('new_business_datetime');
            $table->text('reason');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();

            $table->index(['record_type', 'record_id']);
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_datetime_audits');
    }
};
