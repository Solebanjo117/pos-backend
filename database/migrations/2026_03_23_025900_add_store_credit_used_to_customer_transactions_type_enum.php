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
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: recreate table with new enum values
            DB::statement('
                CREATE TABLE customer_transactions_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    customer_id INTEGER NOT NULL,
                    type TEXT CHECK(type IN (\'sale\', \'payment\', \'credit_note\', \'adjustment\', \'store_credit_used\')) NOT NULL,
                    reference_type VARCHAR(100),
                    reference_id INTEGER,
                    amount NUMERIC(12, 2) NOT NULL,
                    balance_before NUMERIC(12, 2) NOT NULL,
                    balance_after NUMERIC(12, 2) NOT NULL,
                    description TEXT,
                    transaction_date DATETIME NOT NULL,
                    created_by INTEGER,
                    created_at DATETIME,
                    updated_at DATETIME,
                    FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                    FOREIGN KEY(created_by) REFERENCES users(id)
                )
            ');

            // Copy existing data
            DB::statement('
                INSERT INTO customer_transactions_new
                SELECT * FROM customer_transactions
            ');

            // Drop old table and rename new one
            DB::statement('DROP TABLE customer_transactions');
            DB::statement('ALTER TABLE customer_transactions_new RENAME TO customer_transactions');

            // Recreate indexes
            DB::statement('CREATE INDEX customer_transactions_customer_id_index ON customer_transactions(customer_id)');
            DB::statement('CREATE INDEX customer_transactions_type_index ON customer_transactions(type)');
            DB::statement('CREATE INDEX customer_transactions_transaction_date_index ON customer_transactions(transaction_date)');
            DB::statement('CREATE INDEX customer_transactions_reference_type_reference_id_index ON customer_transactions(reference_type, reference_id)');
        } elseif ($driver === 'mysql') {
            // MySQL: use ALTER TABLE with ENUM
            DB::statement("ALTER TABLE customer_transactions MODIFY COLUMN type ENUM('sale', 'payment', 'credit_note', 'adjustment', 'store_credit_used') NOT NULL");
        }
        // PostgreSQL: column is VARCHAR, validation at Laravel level - no schema change needed
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('
                CREATE TABLE customer_transactions_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    customer_id INTEGER NOT NULL,
                    type TEXT CHECK(type IN (\'sale\', \'payment\', \'credit_note\', \'adjustment\')) NOT NULL,
                    reference_type VARCHAR(100),
                    reference_id INTEGER,
                    amount NUMERIC(12, 2) NOT NULL,
                    balance_before NUMERIC(12, 2) NOT NULL,
                    balance_after NUMERIC(12, 2) NOT NULL,
                    description TEXT,
                    transaction_date DATETIME NOT NULL,
                    created_by INTEGER,
                    created_at DATETIME,
                    updated_at DATETIME,
                    FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                    FOREIGN KEY(created_by) REFERENCES users(id)
                )
            ');

            DB::statement('
                INSERT INTO customer_transactions_new
                SELECT * FROM customer_transactions
                WHERE type != \'store_credit_used\'
            ');

            DB::statement('DROP TABLE customer_transactions');
            DB::statement('ALTER TABLE customer_transactions_new RENAME TO customer_transactions');

            DB::statement('CREATE INDEX customer_transactions_customer_id_index ON customer_transactions(customer_id)');
            DB::statement('CREATE INDEX customer_transactions_type_index ON customer_transactions(type)');
            DB::statement('CREATE INDEX customer_transactions_transaction_date_index ON customer_transactions(transaction_date)');
            DB::statement('CREATE INDEX customer_transactions_reference_type_reference_id_index ON customer_transactions(reference_type, reference_id)');
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE customer_transactions MODIFY COLUMN type ENUM('sale', 'payment', 'credit_note', 'adjustment') NOT NULL");
        }
        // PostgreSQL: no-op
    }
};
