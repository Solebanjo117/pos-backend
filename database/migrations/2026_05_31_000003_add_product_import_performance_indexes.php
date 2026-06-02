<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('import_runs', ['entity', 'status', 'created_at'], 'import_runs_entity_status_created_at_idx');
        $this->addIndexIfMissing('import_runs', ['parent_import_run_id', 'status'], 'import_runs_parent_status_idx');
        $this->addIndexIfMissing('product_variants', ['system_id'], 'product_variants_system_id_idx');
        $this->addIndexIfMissing('product_variants', ['brand_id', 'sku'], 'product_variants_brand_sku_idx');
        $this->addIndexIfMissing('products', ['category_id', 'name'], 'products_category_name_idx');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('products', 'products_category_name_idx');
        $this->dropIndexIfExists('product_variants', 'product_variants_brand_sku_idx');
        $this->dropIndexIfExists('product_variants', 'product_variants_system_id_idx');
        $this->dropIndexIfExists('import_runs', 'import_runs_parent_status_idx');
        $this->dropIndexIfExists('import_runs', 'import_runs_entity_status_created_at_idx');
    }

    private function addIndexIfMissing(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $name): void {
            $table->index($columns, $name);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name): void {
            $table->dropIndex($name);
        });
    }

    private function indexExists(string $table, string $name, array $columns = []): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(function (array $index) use ($name, $columns): bool {
                if (($index['name'] ?? null) === $name) {
                    return true;
                }

                return $columns !== [] && ($index['columns'] ?? []) === $columns;
            });
    }
};
