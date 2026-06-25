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
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->timestamp('sent_to_warehouse_at')->nullable()->after('notes');
            $table->timestamp('prepared_at')->nullable()->after('sent_to_warehouse_at');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete()->after('prepared_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign(['prepared_by']);
            $table->dropColumn(['sent_to_warehouse_at', 'prepared_at', 'prepared_by']);
        });
    }
};
