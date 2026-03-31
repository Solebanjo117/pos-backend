<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Remove CHECK constraint on method column to allow dynamic payment methods
     * from the payment_methods table instead of hardcoded values.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL: Drop the CHECK constraint and change to VARCHAR
            // First, find and drop any CHECK constraints on the method column
            $constraintName = DB::selectOne("
                SELECT con.conname
                FROM pg_constraint con
                JOIN pg_class rel ON rel.oid = con.conrelid
                JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
                WHERE rel.relname = 'sales_order_payments'
                AND con.contype = 'c'
                AND pg_get_constraintdef(con.oid) LIKE '%method%'
            ");

            if ($constraintName) {
                DB::statement("ALTER TABLE sales_order_payments DROP CONSTRAINT \"{$constraintName->conname}\"");
            }

            // Change column type to VARCHAR(50) to allow any method code
            DB::statement("ALTER TABLE sales_order_payments ALTER COLUMN method TYPE VARCHAR(50)");

        } elseif ($driver === 'mysql') {
            // MySQL: Change ENUM to VARCHAR
            DB::statement("ALTER TABLE sales_order_payments MODIFY COLUMN method VARCHAR(50) NOT NULL");

        } elseif ($driver === 'sqlite') {
            // SQLite: Recreate table without CHECK constraint
            DB::statement('
                CREATE TABLE sales_order_payments_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    sales_order_id INTEGER NOT NULL,
                    amount NUMERIC(12, 2) NOT NULL,
                    method TEXT NOT NULL,
                    notes TEXT,
                    paid_at DATETIME NOT NULL,
                    created_by INTEGER,
                    created_at DATETIME,
                    updated_at DATETIME,
                    FOREIGN KEY(sales_order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
                    FOREIGN KEY(created_by) REFERENCES users(id)
                )
            ');

            DB::statement('INSERT INTO sales_order_payments_new SELECT * FROM sales_order_payments');
            DB::statement('DROP TABLE sales_order_payments');
            DB::statement('ALTER TABLE sales_order_payments_new RENAME TO sales_order_payments');

            // Recreate indexes
            DB::statement('CREATE INDEX sales_order_payments_sales_order_id_index ON sales_order_payments(sales_order_id)');
            DB::statement('CREATE INDEX sales_order_payments_paid_at_index ON sales_order_payments(paid_at)');
            DB::statement('CREATE INDEX sales_order_payments_method_index ON sales_order_payments(method)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: We don't restore the CHECK constraint because dynamic methods
        // may have been used. The validation is handled at the Laravel level.
    }
};
