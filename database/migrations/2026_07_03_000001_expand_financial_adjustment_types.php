<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $constraints = DB::select("
                SELECT con.conname
                FROM pg_constraint con
                JOIN pg_class rel ON rel.oid = con.conrelid
                JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
                WHERE rel.relname = 'financial_adjustments'
                AND con.contype = 'c'
                AND pg_get_constraintdef(con.oid) LIKE '%type%'
            ");

            foreach ($constraints as $constraint) {
                DB::statement('ALTER TABLE financial_adjustments DROP CONSTRAINT "'.$constraint->conname.'"');
            }

            DB::statement('ALTER TABLE financial_adjustments ALTER COLUMN type TYPE VARCHAR(50)');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE financial_adjustments MODIFY type VARCHAR(50) NOT NULL');
        }

        Schema::table('financial_daily_snapshots', function (Blueprint $table): void {
            $table->decimal('bank_adjustments', 14, 2)->default(0)->after('cash_adjustments');
            $table->decimal('sales_adjustments', 14, 2)->default(0)->after('inventory_adjustments');
            $table->decimal('purchase_adjustments', 14, 2)->default(0)->after('sales_adjustments');
            $table->decimal('expense_adjustments', 14, 2)->default(0)->after('purchase_adjustments');
            $table->decimal('investment_adjustments', 14, 2)->default(0)->after('expense_adjustments');
            $table->decimal('accounts_receivable_adjustments', 14, 2)->default(0)->after('investment_adjustments');
            $table->decimal('accounts_payable_adjustments', 14, 2)->default(0)->after('accounts_receivable_adjustments');
            $table->decimal('manual_adjustments', 14, 2)->default(0)->after('accounts_payable_adjustments');
        });
    }

    public function down(): void
    {
        Schema::table('financial_daily_snapshots', function (Blueprint $table): void {
            $table->dropColumn([
                'bank_adjustments',
                'sales_adjustments',
                'purchase_adjustments',
                'expense_adjustments',
                'investment_adjustments',
                'accounts_receivable_adjustments',
                'accounts_payable_adjustments',
                'manual_adjustments',
            ]);
        });
    }
};
