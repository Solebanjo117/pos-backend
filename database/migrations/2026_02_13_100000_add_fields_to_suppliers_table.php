<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('city', 100)->nullable()->after('address');
            $table->string('country', 100)->default('Panamá')->after('city');
            $table->integer('lead_time_days')->nullable()->after('payment_terms');
            $table->text('notes')->nullable()->after('lead_time_days');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['city', 'country', 'lead_time_days', 'notes']);
            $table->dropSoftDeletes();
        });
    }
};
