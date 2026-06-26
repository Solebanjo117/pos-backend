<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table) {
            $table->timestamp('sent_to_cashier_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table) {
            $table->dropColumn('sent_to_cashier_at');
        });
    }
};
