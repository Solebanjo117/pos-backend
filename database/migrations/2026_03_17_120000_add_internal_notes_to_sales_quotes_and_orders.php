<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table) {
            $table->text('internal_notes')->nullable()->after('notes');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->text('internal_notes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table) {
            $table->dropColumn('internal_notes');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('internal_notes');
        });
    }
};
