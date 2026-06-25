<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add pos_session_id to sales_quotes to track which POS session created/collected the quote.
     * New statuses (sent_to_deposit, sent_to_cashier, collected) are stored as strings — no ENUM change needed.
     */
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table) {
            $table->foreignId('pos_session_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained('pos_sessions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table) {
            $table->dropForeign(['pos_session_id']);
            $table->dropColumn('pos_session_id');
        });
    }
};
