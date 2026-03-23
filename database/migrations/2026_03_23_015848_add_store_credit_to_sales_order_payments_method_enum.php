<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite doesn't support ALTER COLUMN for ENUM, so we need to recreate the table
        // For MySQL/PostgreSQL in production, this would be a simple ALTER

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: recreate table with new enum values
            DB::statement('
                CREATE TABLE sales_order_payments_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    sales_order_id INTEGER NOT NULL,
                    amount NUMERIC(12, 2) NOT NULL,
                    method TEXT CHECK(method IN (\'cash\', \'card\', \'transfer\', \'credit\', \'store_credit\')) NOT NULL,
                    notes TEXT,
                    paid_at DATETIME NOT NULL,
                    created_by INTEGER,
                    created_at DATETIME,
                    updated_at DATETIME,
                    FOREIGN KEY(sales_order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
                    FOREIGN KEY(created_by) REFERENCES users(id)
                )
            ');

            // Copy existing data
            DB::statement('
                INSERT INTO sales_order_payments_new
                SELECT * FROM sales_order_payments
            ');

            // Drop old table and rename new one
            DB::statement('DROP TABLE sales_order_payments');
            DB::statement('ALTER TABLE sales_order_payments_new RENAME TO sales_order_payments');

            // Recreate indexes
            DB::statement('CREATE INDEX sales_order_payments_sales_order_id_index ON sales_order_payments(sales_order_id)');
            DB::statement('CREATE INDEX sales_order_payments_paid_at_index ON sales_order_payments(paid_at)');
            DB::statement('CREATE INDEX sales_order_payments_method_index ON sales_order_payments(method)');
        } else {
            // MySQL/PostgreSQL: use ALTER TABLE
            DB::statement("ALTER TABLE sales_order_payments MODIFY COLUMN method ENUM('cash', 'card', 'transfer', 'credit', 'store_credit') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('
                CREATE TABLE sales_order_payments_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    sales_order_id INTEGER NOT NULL,
                    amount NUMERIC(12, 2) NOT NULL,
                    method TEXT CHECK(method IN (\'cash\', \'card\', \'transfer\', \'credit\')) NOT NULL,
                    notes TEXT,
                    paid_at DATETIME NOT NULL,
                    created_by INTEGER,
                    created_at DATETIME,
                    updated_at DATETIME,
                    FOREIGN KEY(sales_order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
                    FOREIGN KEY(created_by) REFERENCES users(id)
                )
            ');

            DB::statement('
                INSERT INTO sales_order_payments_new
                SELECT * FROM sales_order_payments
                WHERE method != \'store_credit\'
            ');

            DB::statement('DROP TABLE sales_order_payments');
            DB::statement('ALTER TABLE sales_order_payments_new RENAME TO sales_order_payments');

            DB::statement('CREATE INDEX sales_order_payments_sales_order_id_index ON sales_order_payments(sales_order_id)');
            DB::statement('CREATE INDEX sales_order_payments_paid_at_index ON sales_order_payments(paid_at)');
            DB::statement('CREATE INDEX sales_order_payments_method_index ON sales_order_payments(method)');
        } else {
            DB::statement("ALTER TABLE sales_order_payments MODIFY COLUMN method ENUM('cash', 'card', 'transfer', 'credit') NOT NULL");
        }
    }
};
