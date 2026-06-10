<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('import_runs')) {
            Schema::table('import_runs', function (Blueprint $table): void {
                if (! Schema::hasColumn('import_runs', 'phase')) {
                    $table->string('phase', 40)->nullable()->after('status');
                }

                if (! Schema::hasColumn('import_runs', 'heartbeat_at')) {
                    $table->timestamp('heartbeat_at')->nullable()->after('finished_at');
                }
            });

            $this->addIndexIfMissing('import_runs', ['entity', 'status', 'heartbeat_at'], 'import_runs_entity_status_heartbeat_idx');
        }

        if (Schema::hasTable('import_run_rows')) {
            Schema::table('import_run_rows', function (Blueprint $table): void {
                if (! Schema::hasColumn('import_run_rows', 'product_group_key')) {
                    $table->string('product_group_key')->nullable()->after('search_text');
                }

                if (! Schema::hasColumn('import_run_rows', 'variant_key')) {
                    $table->string('variant_key')->nullable()->after('product_group_key');
                }

                if (! Schema::hasColumn('import_run_rows', 'conflict_key')) {
                    $table->string('conflict_key')->nullable()->after('variant_key');
                }

                if (! Schema::hasColumn('import_run_rows', 'execution_status')) {
                    $table->string('execution_status', 30)->nullable()->after('action_type');
                }

                if (! Schema::hasColumn('import_run_rows', 'executed_at')) {
                    $table->timestamp('executed_at')->nullable()->after('validated_at');
                }

                if (! Schema::hasColumn('import_run_rows', 'execution_error')) {
                    $table->text('execution_error')->nullable()->after('executed_at');
                }
            });

            $this->addIndexIfMissing('import_run_rows', ['import_run_id', 'conflict_key'], 'import_run_rows_run_conflict_key_idx');
            $this->addIndexIfMissing('import_run_rows', ['import_run_id', 'execution_status'], 'import_run_rows_run_execution_status_idx');
            $this->addIndexIfMissing('import_run_rows', ['import_run_id', 'product_group_key'], 'import_run_rows_run_product_group_idx');
            $this->addIndexIfMissing('import_run_rows', ['import_run_id', 'status', 'id'], 'import_run_rows_run_status_id_idx');
        }

        if (Schema::hasTable('import_run_records')) {
            $this->addUniqueIfMissing('import_run_records', ['import_run_id', 'model_type', 'model_id', 'action'], 'import_run_records_run_model_action_unique');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('import_run_records')) {
            $this->dropUniqueIfExists('import_run_records', 'import_run_records_run_model_action_unique');
        }

        if (Schema::hasTable('import_run_rows')) {
            $this->dropIndexIfExists('import_run_rows', 'import_run_rows_run_status_id_idx');
            $this->dropIndexIfExists('import_run_rows', 'import_run_rows_run_product_group_idx');
            $this->dropIndexIfExists('import_run_rows', 'import_run_rows_run_execution_status_idx');
            $this->dropIndexIfExists('import_run_rows', 'import_run_rows_run_conflict_key_idx');

            Schema::table('import_run_rows', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    Schema::hasColumn('import_run_rows', 'product_group_key') ? 'product_group_key' : null,
                    Schema::hasColumn('import_run_rows', 'variant_key') ? 'variant_key' : null,
                    Schema::hasColumn('import_run_rows', 'conflict_key') ? 'conflict_key' : null,
                    Schema::hasColumn('import_run_rows', 'execution_status') ? 'execution_status' : null,
                    Schema::hasColumn('import_run_rows', 'executed_at') ? 'executed_at' : null,
                    Schema::hasColumn('import_run_rows', 'execution_error') ? 'execution_error' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('import_runs')) {
            $this->dropIndexIfExists('import_runs', 'import_runs_entity_status_heartbeat_idx');

            Schema::table('import_runs', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    Schema::hasColumn('import_runs', 'phase') ? 'phase' : null,
                    Schema::hasColumn('import_runs', 'heartbeat_at') ? 'heartbeat_at' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }

    private function addIndexIfMissing(string $table, array $columns, string $name): void
    {
        if ($this->indexExists($table, $name, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $name): void {
            $table->index($columns, $name);
        });
    }

    private function addUniqueIfMissing(string $table, array $columns, string $name): void
    {
        if ($this->indexExists($table, $name, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $name): void {
            $table->unique($columns, $name);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name): void {
            $table->dropIndex($name);
        });
    }

    private function dropUniqueIfExists(string $table, string $name): void
    {
        if (! $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name): void {
            $table->dropUnique($name);
        });
    }

    private function indexExists(string $table, string $name, array $columns = []): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        return collect(Schema::getIndexes($table))
            ->contains(function (array $index) use ($name, $columns): bool {
                if (($index['name'] ?? null) === $name) {
                    return true;
                }

                return $columns !== [] && ($index['columns'] ?? []) === $columns;
            });
    }
};
