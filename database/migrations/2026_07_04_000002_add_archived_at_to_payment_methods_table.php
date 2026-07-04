<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_methods', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('is_active')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            if (Schema::hasColumn('payment_methods', 'archived_at')) {
                $table->dropColumn('archived_at');
            }
        });
    }
};
