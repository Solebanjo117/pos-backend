<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addCustomLineColumns('sales_order_items');
        $this->addCustomLineColumns('sales_quote_items');

        $this->makeProductIdNullable('sales_order_items');
        $this->makeProductIdNullable('sales_quote_items');
    }

    public function down(): void
    {
        $this->dropCustomLineColumns('sales_order_items');
        $this->dropCustomLineColumns('sales_quote_items');

        $this->makeProductIdNotNullableIfSafe('sales_order_items');
        $this->makeProductIdNotNullableIfSafe('sales_quote_items');
    }

    private function addCustomLineColumns(string $table): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (!Schema::hasColumn($table, 'is_custom_line')) {
                $blueprint->boolean('is_custom_line')->default(false);
            }

            if (!Schema::hasColumn($table, 'custom_description')) {
                $blueprint->string('custom_description', 255)->nullable();
            }
        });
    }

    private function dropCustomLineColumns(string $table): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (Schema::hasColumn($table, 'custom_description')) {
                $blueprint->dropColumn('custom_description');
            }

            if (Schema::hasColumn($table, 'is_custom_line')) {
                $blueprint->dropColumn('is_custom_line');
            }
        });
    }

    private function makeProductIdNullable(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'product_id')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN product_id DROP NOT NULL");
            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY product_id BIGINT UNSIGNED NULL");
            return;
        }
    }

    private function makeProductIdNotNullableIfSafe(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'product_id')) {
            return;
        }

        if (DB::table($table)->whereNull('product_id')->exists()) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN product_id SET NOT NULL");
            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY product_id BIGINT UNSIGNED NOT NULL");
            return;
        }
    }
};
