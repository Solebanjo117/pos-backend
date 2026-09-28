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
        Schema::table('sales_order_payments', function (Blueprint $table): void {
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_order_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pos_session_id');
        });
    }
};
