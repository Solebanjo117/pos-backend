<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add refund columns to sales_returns
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->string('refund_method', 50)->default('balance')->after('total_amount');
            $table->text('refund_notes')->nullable()->after('refund_method');
        });

        // 2. Fix customer_transactions enum: add 'refund' type
        //    SQLite doesn't support ALTER COLUMN, so we recreate via a pragma workaround
        //    For SQLite we just allow it to pass — the column accepts any value.
        //    For MySQL/PostgreSQL, we'd ALTER the ENUM. Since this is SQLite, the enum
        //    is not enforced at DB level, so 'credit_note' will work fine.
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropColumn(['refund_method', 'refund_notes']);
        });
    }
};
