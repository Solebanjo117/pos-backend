<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_run_rows')) {
            return;
        }

        Schema::table('import_run_rows', function (Blueprint $table): void {
            if (! Schema::hasColumn('import_run_rows', 'brand_key')) {
                $table->string('brand_key')->nullable()->after('conflict_key');
            }

            if (! Schema::hasColumn('import_run_rows', 'category_key')) {
                $table->string('category_key')->nullable()->after('brand_key');
            }
        });

        $this->addIndexIfMissing('import_run_rows', ['import_run_id', 'brand_key'], 'import_run_rows_run_brand_key_idx');
        $this->addIndexIfMissing('import_run_rows', ['import_run_id', 'category_key'], 'import_run_rows_run_category_key_idx');

        DB::table('import_run_rows')
            ->select(['id', 'data'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $data = $this->decodedData($row->data);

                    DB::table('import_run_rows')
                        ->where('id', $row->id)
                        ->update([
                            'brand_key' => $this->referenceKey('br', $this->brandReferenceValue($data)),
                            'category_key' => $this->referenceKey('cat', $this->categoryReferenceValue($data)),
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('import_run_rows')) {
            return;
        }

        $this->dropIndexIfExists('import_run_rows', 'import_run_rows_run_category_key_idx');
        $this->dropIndexIfExists('import_run_rows', 'import_run_rows_run_brand_key_idx');

        Schema::table('import_run_rows', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('import_run_rows', 'brand_key') ? 'brand_key' : null,
                Schema::hasColumn('import_run_rows', 'category_key') ? 'category_key' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedData(mixed $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        if (! is_string($data) || trim($data) === '') {
            return [];
        }

        $decoded = json_decode($data, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function brandReferenceValue(array $data): ?string
    {
        $brand = $this->nonEmptyString($data['brand'] ?? null);

        if ($brand !== null) {
            return $brand;
        }

        return $this->rowHasVariantPayload($data) ? 'Generica' : null;
    }

    private function categoryReferenceValue(array $data): ?string
    {
        return $this->nonEmptyString($data['__category_label'] ?? null)
            ?? $this->nonEmptyString($data['categoria'] ?? null);
    }

    private function rowHasVariantPayload(array $data): bool
    {
        foreach (['system_id', 'costo', 'precio', 'cantidad'] as $key) {
            if ($this->nonEmptyString($data[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    private function referenceKey(string $prefix, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $prefix.':'.hash('sha256', $this->normalizeImportKey($value));
    }

    private function normalizeImportKey(string $value): string
    {
        return mb_strtolower(trim(Str::ascii($value)));
    }

    private function nonEmptyString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string === '' ? null : $string;
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

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name): void {
            $table->dropIndex($name);
        });
    }

    private function indexExists(string $table, string $name, ?array $columns = null): bool
    {
        $connection = Schema::getConnection();
        $schemaBuilder = $connection->getSchemaBuilder();

        if (method_exists($schemaBuilder, 'getIndexes')) {
            foreach ($schemaBuilder->getIndexes($table) as $index) {
                $indexName = $index['name'] ?? null;
                if ($indexName === $name) {
                    return true;
                }

                if ($columns !== null && array_values($index['columns'] ?? []) === array_values($columns)) {
                    return true;
                }
            }
        }

        return false;
    }
};
