<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_attributes')) {
            return;
        }

        Schema::table('product_attributes', function (Blueprint $table): void {
            if (! Schema::hasColumn('product_attributes', 'source')) {
                $table->string('source')->default('custom')->after('unit');
            }

            if (! Schema::hasColumn('product_attributes', 'source_category_id')) {
                $table->unsignedBigInteger('source_category_id')->nullable()->after('source');
            }
        });

        if (
            Schema::hasColumn('product_attributes', 'source_category_id') &&
            ! $this->foreignKeyExists('product_attributes', 'source_category_id')
        ) {
            Schema::table('product_attributes', function (Blueprint $table): void {
                $table->foreign('source_category_id')
                    ->references('id')
                    ->on('categories')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_attributes')) {
            return;
        }

        Schema::table('product_attributes', function (Blueprint $table): void {
            if ($this->foreignKeyExists('product_attributes', 'source_category_id')) {
                $table->dropForeign(['source_category_id']);
            }

            $columns = array_values(array_filter(
                ['source', 'source_category_id'],
                fn (string $column): bool => Schema::hasColumn('product_attributes', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $constraints = DB::select("PRAGMA foreign_key_list('{$table}')");

            return collect($constraints)->contains(
                fn (object $constraint): bool => ($constraint->from ?? null) === $column,
            );
        }

        if ($driver === 'pgsql') {
            return DB::table('information_schema.key_column_usage as kcu')
                ->join('information_schema.table_constraints as tc', function ($join): void {
                    $join->on('kcu.constraint_name', '=', 'tc.constraint_name')
                        ->on('kcu.table_schema', '=', 'tc.table_schema');
                })
                ->where('tc.constraint_type', 'FOREIGN KEY')
                ->where('kcu.table_name', $table)
                ->where('kcu.column_name', $column)
                ->exists();
        }

        return DB::table('information_schema.key_column_usage')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->whereNotNull('referenced_table_name')
            ->exists();
    }
};
