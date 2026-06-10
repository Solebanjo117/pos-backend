<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_run_rows')) {
            return;
        }

        foreach (['product_group_key', 'variant_key', 'conflict_key'] as $column) {
            if (! Schema::hasColumn('import_run_rows', $column)) {
                return;
            }
        }

        DB::table('import_run_rows')
            ->select(['id', 'data', 'product_group_key', 'variant_key', 'conflict_key'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $data = $this->decodedData($row->data);
                    $identity = $this->identityColumns(
                        $data,
                        $row->product_group_key,
                        $row->variant_key,
                        $row->conflict_key,
                    );

                    DB::table('import_run_rows')
                        ->where('id', $row->id)
                        ->update($identity);
                }
            });
    }

    public function down(): void
    {
        //
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

    /**
     * @return array{product_group_key: ?string, variant_key: ?string, conflict_key: ?string}
     */
    private function identityColumns(array $data, mixed $storedProductGroupKey, mixed $storedVariantKey, mixed $storedConflictKey): array
    {
        $productGroupKey = $this->nonEmptyString($data['__product_group_key'] ?? $data['__product_key'] ?? null)
            ?? $this->nonEmptyString($storedProductGroupKey);
        $variantPreview = is_array($data['__variant_preview'] ?? null)
            ? $data['__variant_preview']
            : null;
        $variantKey = $this->nonEmptyString($variantPreview['variant_key'] ?? null)
            ?? $this->nonEmptyString($storedVariantKey);
        $conflictKey = $productGroupKey !== null && $variantKey !== null
            ? $productGroupKey.'|'.$variantKey
            : $this->nonEmptyString($storedConflictKey);

        return [
            'product_group_key' => $this->technicalIdentityKey('pg', $productGroupKey),
            'variant_key' => $this->technicalIdentityKey('vk', $variantKey),
            'conflict_key' => $this->technicalIdentityKey('ck', $conflictKey),
        ];
    }

    private function technicalIdentityKey(string $prefix, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^'.$prefix.':[a-f0-9]{64}$/', $value) === 1) {
            return $value;
        }

        return $prefix.':'.hash('sha256', $value);
    }

    private function nonEmptyString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string === '' ? null : $string;
    }
};
