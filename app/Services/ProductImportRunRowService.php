<?php

namespace App\Services;

use App\Imports\ProductImport;
use App\Models\ImportRun;
use App\Models\ImportRunRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductImportRunRowService
{
    private const OPERATIONAL_MAPPING_TARGETS = ['costo', 'precio', 'cantidad', 'descripcion'];

    private const LEGACY_HIDDEN_MAPPING_TARGETS = ['imagen_url'];

    public function __construct(private readonly ProductService $productService) {}

    public function persistValidationRows(ImportRun $importRun, ProductImport $import): void
    {
        $this->replaceRows($importRun, $import->validationRows);
    }

    public function replaceRows(ImportRun $importRun, array $validationRows, bool $preserveEdited = false): void
    {
        $editedRows = $preserveEdited
            ? $importRun->rows()->pluck('is_edited', 'row_number')
            : collect();

        $importRun->rows()->delete();

        foreach (array_chunk($validationRows, 500) as $chunk) {
            $now = now();
            $rows = array_map(fn (array $row): array => $this->validationRowAttributes(
                $importRun,
                $row,
                (bool) ($editedRows[(int) ($row['row'] ?? 0)] ?? false),
                $now
            ), $chunk);

            ImportRunRow::query()->insert($rows);
        }
    }

    public function updateRows(ImportRun $importRun, array $data): array
    {
        return DB::transaction(function () use ($importRun, $data): array {
            $lockedRun = ImportRun::query()->lockForUpdate()->findOrFail($importRun->id);
            $summary = $lockedRun->summary ?? [];

            if (! $this->isEditableValidationRun($lockedRun)) {
                abort(409, 'La validacion seleccionada no admite correcciones.');
            }

            if (in_array((string) ($summary['full_revalidation_status'] ?? 'completed'), ['pending', 'running'], true)) {
                abort(409, 'La revalidacion completa esta en proceso. Espere a que termine antes de editar filas.');
            }

            if (ImportRun::query()
                ->where('parent_import_run_id', $lockedRun->id)
                ->whereIn('status', [ImportRun::STATUS_PENDING, ImportRun::STATUS_RUNNING, ImportRun::STATUS_CANCEL_REQUESTED])
                ->exists()) {
                abort(409, 'La validacion seleccionada ya tiene una importacion activa.');
            }

            if ((int) ($summary['rows_revision'] ?? 0) !== (int) $data['rows_revision']) {
                abort(409, 'Las filas cambiaron desde que se cargaron. Actualice la pagina antes de guardar.');
            }

            $rowsById = $lockedRun->rows()
                ->whereKey(collect($data['rows'])->pluck('id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($rowsById->count() !== count($data['rows'])) {
                abort(404);
            }

            $payloads = [];
            foreach ($data['rows'] as $rowData) {
                $row = $rowsById->get($rowData['id']);
                if (! $row->updated_at?->equalTo(Carbon::parse($rowData['updated_at']))) {
                    abort(409, 'Una fila cambio desde que se cargo. Actualice la pagina antes de guardar.');
                }

                $payloads[] = array_merge($rowData['data'], ['__row' => $row->row_number]);
            }

            $import = (new ProductImport($this->productService))->forMode(ImportRun::MODE_VALIDATE_ONLY);
            $import->validateRows($payloads);

            foreach ($import->validationRows as $validationRow) {
                $row = $lockedRun->rows()->where('row_number', $validationRow['row'])->firstOrFail();
                $row->update($this->preserveSourceDataAttributes(
                    $this->validationRowModelAttributes($validationRow, true),
                    $row
                ));
            }

            $revision = (int) ($summary['rows_revision'] ?? 0) + 1;
            $lockedRun->update([
                'summary' => array_merge($summary, $this->summaryCounts($lockedRun), $this->groupingSummary($lockedRun), [
                    'rows_revision' => $revision,
                    'rows_dirty' => true,
                    'full_revalidation_status' => 'required',
                ]),
            ]);

            return [
                'rows_revision' => $revision,
                'rows_dirty' => true,
                'full_revalidation_status' => 'required',
                'rows' => $lockedRun->rows()->whereKey($rowsById->keys())->orderBy('row_number')->get(),
            ];
        });
    }

    public function createSnapshot(ImportRun $sourceRun, ImportRun $targetRun): void
    {
        $sourceRun->rows()->orderBy('id')->chunkById(500, function ($rows) use ($targetRun): void {
            $now = now();
            ImportRunRow::query()->insert($rows->map(fn (ImportRunRow $row): array => [
                'import_run_id' => $targetRun->id,
                'row_number' => $row->row_number,
                'sheet_name' => $row->sheet_name,
                'status' => $row->status,
                'action_type' => $row->action_type,
                'label' => $row->label,
                'search_text' => $row->search_text,
                'is_edited' => $row->is_edited,
                'validated_at' => $row->validated_at,
                'data' => json_encode($row->data ?? [], JSON_UNESCAPED_UNICODE),
                'source_data' => json_encode($row->source_data ?? [], JSON_UNESCAPED_UNICODE),
                'errors' => json_encode($row->errors ?? [], JSON_UNESCAPED_UNICODE),
                'warnings' => json_encode($row->warnings ?? [], JSON_UNESCAPED_UNICODE),
                'conflicts' => json_encode($row->conflicts ?? [], JSON_UNESCAPED_UNICODE),
                'change_preview' => json_encode($row->change_preview ?? [], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
    }

    public function executableRows(ImportRun $importRun): array
    {
        $rows = [];

        $this->eachExecutableRowChunk($importRun, 500, function (array $chunk) use (&$rows): void {
            array_push($rows, ...$chunk);
        });

        return $rows;
    }

    public function duplicateKeysForRun(ImportRun $importRun, ProductImport $import, int $chunkSize = 500): array
    {
        $duplicateKeys = [];

        $this->eachExecutableRowChunk($importRun, $chunkSize, function (array $chunk) use ($import, &$duplicateKeys): void {
            foreach ($import->duplicateRowKeysForRows($chunk) as $key => $count) {
                $duplicateKeys[$key] = (int) ($duplicateKeys[$key] ?? 0) + (int) $count;
            }
        });

        return $duplicateKeys;
    }

    public function revalidatePersistedRows(ImportRun $importRun, ProductImport $import, int $chunkSize = 500, ?callable $afterChunk = null): void
    {
        $duplicateKeys = $this->duplicateKeysForRun($importRun, $import, $chunkSize);

        $this->eachExecutableRowChunk($importRun, $chunkSize, function (array $chunk) use ($importRun, $import, $duplicateKeys, $afterChunk): void {
            $import->validateRowsWithDuplicateKeys($chunk, $duplicateKeys);
            $this->persistValidationRowsChunk($importRun, $import->validationRows, true);
            $import->validationRows = [];

            if ($afterChunk !== null) {
                $afterChunk($import);
            }
        });
    }

    public function eachExecutableRowChunk(ImportRun $importRun, int $chunkSize, callable $callback): void
    {
        $importRun->rows()
            ->select(['id', 'row_number', 'data', 'source_data'])
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $rows) use ($callback, $importRun): void {
                $callback($rows
                    ->map(fn (ImportRunRow $row): array => array_merge(
                        $this->legacyHiddenDataFromSource($row->source_data ?? [], $importRun->mapping ?? []),
                        $row->data ?? [],
                        [
                            '__row' => $row->row_number,
                            '__source_data' => $row->source_data ?? [],
                        ]
                    ))
                    ->all());
            });
    }

    public function applyOperationalMapping(ImportRun $importRun, array $mapping, array $visibleRowIds, int $rowsRevision): array
    {
        return DB::transaction(function () use ($importRun, $mapping, $visibleRowIds, $rowsRevision): array {
            $lockedRun = ImportRun::query()->lockForUpdate()->findOrFail($importRun->id);
            $summary = $lockedRun->summary ?? [];

            if (! $this->isEditableValidationRun($lockedRun)) {
                abort(409, 'La validacion seleccionada no admite cambios de mapping.');
            }

            if ((int) ($summary['rows_revision'] ?? 0) !== $rowsRevision) {
                abort(409, 'Las filas cambiaron desde que se cargo el mapping. Actualice la pagina antes de continuar.');
            }

            if (in_array((string) ($summary['full_revalidation_status'] ?? 'completed'), ['pending', 'running'], true)) {
                abort(409, 'La revalidacion completa esta en proceso. Espere a que termine antes de cambiar el mapping.');
            }

            if ($this->hasActiveChildImport($lockedRun)) {
                abort(409, 'La validacion seleccionada ya tiene una importacion activa.');
            }

            if (! $this->isOperationalOnlyMappingChange($lockedRun->mapping ?? [], $mapping)) {
                return $this->fullRestartRequiredResponse('El cambio de mapping requiere reiniciar la validacion completa.');
            }

            if ($lockedRun->rows()->whereNull('source_data')->exists()) {
                return $this->fullRestartRequiredResponse('Esta importacion no conserva las celdas originales para remapeo rapido.');
            }

            $revision = (int) ($summary['rows_revision'] ?? 0) + 1;
            $visibleRows = collect();
            $visibleIds = collect($visibleRowIds)->map(fn ($id): int => (int) $id)->filter()->values()->all();

            $lockedRun->rows()
                ->select(['id', 'row_number', 'data', 'source_data', 'is_edited'])
                ->orderBy('id')
                ->chunkById(500, function (Collection $rows) use ($mapping, $visibleIds, &$visibleRows): void {
                    foreach ($rows as $row) {
                        $data = $this->applyOperationalDataFromSource($row->data ?? [], $row->source_data ?? [], $mapping);
                        $row->update([
                            'data' => $data,
                            'search_text' => $this->searchText($data),
                            'is_edited' => true,
                        ]);

                        if (in_array((int) $row->id, $visibleIds, true)) {
                            $row->data = $data;
                            $visibleRows->push($row);
                        }
                    }
                });

            $validatedRows = [];
            if ($visibleRows->isNotEmpty()) {
                $import = (new ProductImport($this->productService))->forMode(ImportRun::MODE_VALIDATE_ONLY);
                $payloads = $visibleRows
                    ->map(fn (ImportRunRow $row): array => array_merge($row->data ?? [], ['__row' => $row->row_number]))
                    ->all();
                $import->validateRows($payloads);

                foreach ($import->validationRows as $validationRow) {
                    $row = $lockedRun->rows()->where('row_number', $validationRow['row'])->firstOrFail();
                    $row->update($this->preserveSourceDataAttributes(
                        $this->validationRowModelAttributes($validationRow, true),
                        $row
                    ));
                }

                $validatedRows = $lockedRun->rows()
                    ->whereIn('id', $visibleIds)
                    ->orderBy('row_number')
                    ->get();
            }

            $lockedRun->update([
                'mapping' => $mapping,
                'summary' => array_merge($summary, [
                    'rows_revision' => $revision,
                    'rows_dirty' => true,
                    'full_revalidation_status' => 'pending',
                    'pending_operational_mapping' => $mapping,
                ]),
            ]);

            return [
                'requires_full_restart' => false,
                'rows_revision' => $revision,
                'rows_dirty' => true,
                'full_revalidation_status' => 'pending',
                'rows' => $validatedRows,
            ];
        });
    }

    public function persistValidationRowsChunk(ImportRun $importRun, array $validationRows, bool $preserveEdited = false): void
    {
        $editedRows = $preserveEdited
            ? $importRun->rows()->whereIn('row_number', collect($validationRows)->pluck('row'))->pluck('is_edited', 'row_number')
            : collect();

        foreach ($validationRows as $validationRow) {
            $rowNumber = (int) ($validationRow['row'] ?? 0);
            $attributes = $this->validationRowModelAttributes(
                $validationRow,
                (bool) ($editedRows[$rowNumber] ?? false)
            );

            $importRun->rows()->updateOrCreate(
                ['row_number' => $rowNumber],
                array_merge($attributes, [
                    'sheet_name' => $importRun->sheet_name,
                ])
            );
        }
    }

    public function synchronizeExecutionErrors(ImportRun $importRun, ProductImport $import): void
    {
        $errorsByRow = collect($import->errors)->keyBy('row');

        $importRun->rows()->update([
            'status' => 'valid',
            'errors' => json_encode([], JSON_UNESCAPED_UNICODE),
            'conflicts' => json_encode([], JSON_UNESCAPED_UNICODE),
        ]);

        $importRun->rows()
            ->whereIn('row_number', $errorsByRow->keys())
            ->select(['id', 'row_number', 'change_preview'])
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows) use ($errorsByRow): void {
                foreach ($rows as $row) {
                    $error = $errorsByRow->get($row->row_number);
                    $row->update([
                        'status' => 'error',
                        'errors' => [$error['error']],
                        'conflicts' => $error['conflicts'] ?? [],
                        'change_preview' => $error['change_preview'] ?? $row->change_preview ?? [],
                    ]);
                }
            });
    }

    public function summaryCounts(ImportRun $importRun): array
    {
        $statusCounts = $importRun->rows()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $actionCounts = $importRun->rows()
            ->whereNotNull('action_type')
            ->select('action_type', DB::raw('count(*) as total'))
            ->groupBy('action_type')
            ->pluck('total', 'action_type')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $warningCount = $importRun->rows()
            ->whereNotNull('warnings')
            ->whereRaw("CAST(warnings AS TEXT) != '[]'")
            ->count();

        $conflictCount = $importRun->rows()
            ->whereNotNull('conflicts')
            ->whereRaw("CAST(conflicts AS TEXT) != '[]'")
            ->count();

        return [
            'validation_rows_count' => array_sum($statusCounts),
            'row_status_counts' => $statusCounts,
            'action_counts' => $actionCounts,
            'warning_count' => $warningCount,
            'conflict_count' => $conflictCount,
            'retryable_failed_rows_count' => $this->retryableFailedRowsCount($importRun),
        ];
    }

    public function groupingSummary(ImportRun $importRun): array
    {
        $groups = [];

        $importRun->rows()
            ->select(['id', 'data'])
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows) use (&$groups): void {
                foreach ($rows as $row) {
                    $data = $row->data ?? [];
                    $productKey = trim((string) ($data['__product_group_key'] ?? $data['__product_key'] ?? ''));

                    if ($productKey === '') {
                        continue;
                    }

                    $variantPreview = is_array($data['__variant_preview'] ?? null)
                        ? $data['__variant_preview']
                        : null;

                    $groups[$productKey] ??= [
                        'product_key' => $productKey,
                        'product_name' => $data['__clean_name'] ?? $data['nombre'] ?? '',
                        'original_name' => $data['__original_name'] ?? $data['nombre'] ?? '',
                        'clean_name' => $data['__clean_name'] ?? $data['nombre'] ?? '',
                        'attributes_fingerprint' => $data['__attributes_fingerprint'] ?? '',
                        'rows' => 0,
                        'variant_keys' => [],
                        'variant_skus' => [],
                        'variants' => [],
                    ];

                    $groups[$productKey]['rows']++;
                    $groups[$productKey]['variant_keys'][] = $this->variantGroupingKey($data, $variantPreview);

                    $variantSku = $this->nonEmptyString($variantPreview['sku'] ?? $data['sku'] ?? null);
                    if ($variantSku !== null) {
                        $groups[$productKey]['variant_skus'][] = $variantSku;
                    }

                    if ($variantPreview !== null) {
                        $groups[$productKey]['variants'][] = $variantPreview;
                    }
                }
            });

        return [
            'groups' => collect($groups)
                ->map(function (array $group): array {
                    $variantSkus = collect($group['variant_skus'])->filter()->unique()->values();

                    return [
                        'product_key' => $group['product_key'],
                        'product_name' => $group['product_name'],
                        'original_name' => $group['original_name'],
                        'clean_name' => $group['clean_name'],
                        'attributes_fingerprint' => $group['attributes_fingerprint'],
                        'rows' => $group['rows'],
                        'variant_count' => collect($group['variant_keys'])->filter()->unique()->count(),
                        'variant_skus' => $variantSkus->all(),
                        'variants' => collect($group['variants'])
                            ->unique(fn (array $variant): string => (string) ($variant['variant_key'] ?? ''))
                            ->values()
                            ->all(),
                        'sku_warning' => $variantSkus->count() > 1
                            ? 'El codigo cambia por variante; se importara como SKU de variante.'
                            : null,
                    ];
                })
                ->values()
                ->all(),
            'productos_estimados' => count($groups),
            'variantes_estimadas' => collect($groups)
                ->sum(fn (array $group): int => collect($group['variant_keys'])->filter()->unique()->count()),
        ];
    }

    public function retryableFailedRowsCount(ImportRun $importRun): int
    {
        $persistedFailedRows = $importRun->rows()
            ->where('status', 'error')
            ->whereNotNull('data')
            ->count();

        if ($persistedFailedRows > 0) {
            return $persistedFailedRows;
        }

        return collect($importRun->failed_rows ?: $importRun->errors ?: [])
            ->filter(fn ($row): bool => is_array($row)
                && ! empty($row['data'])
                && is_array($row['data']))
            ->count();
    }

    public function searchText(array $data): string
    {
        return mb_strtolower(implode(' ', array_filter([
            $data['nombre'] ?? null,
            $data['sku'] ?? null,
            $data['system_id'] ?? null,
            $data['brand'] ?? null,
            $data['categoria'] ?? null,
        ], fn ($value): bool => is_scalar($value) && trim((string) $value) !== '')));
    }

    private function variantGroupingKey(array $data, ?array $variantPreview): string
    {
        $variantKey = $this->nonEmptyString($variantPreview['variant_key'] ?? null);
        if ($variantKey !== null) {
            return $variantKey;
        }

        $systemId = $this->nonEmptyString($data['system_id'] ?? null);
        if ($systemId !== null) {
            return 'system:'.$systemId;
        }

        $sku = $this->nonEmptyString($data['sku'] ?? null);
        if ($sku !== null) {
            return 'sku:'.$sku;
        }

        $brand = $this->normalizeImportKey((string) ($data['brand'] ?? 'Generica'));
        $name = $this->normalizeImportKey((string) ($data['__original_name'] ?? $data['nombre'] ?? ''));

        return 'brand:'.$brand.'|name:'.$name;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string === '' ? null : $string;
    }

    private function normalizeImportKey(string $value): string
    {
        return mb_strtolower(trim(Str::ascii($value)));
    }

    private function validationRowAttributes(ImportRun $importRun, array $row, bool $isEdited, Carbon $now): array
    {
        return array_merge([
            'import_run_id' => $importRun->id,
            'row_number' => (int) ($row['row'] ?? 0),
            'sheet_name' => $importRun->sheet_name,
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->validationRowDatabaseAttributes($row, $isEdited));
    }

    private function validationRowModelAttributes(array $row, bool $isEdited): array
    {
        $attributes = $this->validationRowDatabaseAttributes($row, $isEdited);

        foreach (['data', 'source_data', 'errors', 'warnings', 'conflicts', 'change_preview'] as $key) {
            $attributes[$key] = json_decode($attributes[$key], true);
        }

        return $attributes;
    }

    private function preserveSourceDataAttributes(array $attributes, ImportRunRow $row): array
    {
        if (($attributes['source_data'] ?? null) === null && $row->source_data !== null) {
            unset($attributes['source_data']);
        }

        return $attributes;
    }

    private function validationRowDatabaseAttributes(array $row, bool $isEdited): array
    {
        $data = $row['data'] ?? [];
        $errors = $row['errors'] ?? [];
        $conflicts = $row['conflicts'] ?? [];

        return [
            'status' => ($row['is_valid'] ?? false) ? 'valid' : 'error',
            'action_type' => $row['action_type'] ?? null,
            'label' => $row['label'] ?? ($data['nombre'] ?? null),
            'search_text' => $this->searchText($data),
            'is_edited' => $isEdited,
            'validated_at' => now(),
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'source_data' => json_encode($row['source_data'] ?? null, JSON_UNESCAPED_UNICODE),
            'errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
            'warnings' => json_encode($row['warnings'] ?? [], JSON_UNESCAPED_UNICODE),
            'conflicts' => json_encode($conflicts, JSON_UNESCAPED_UNICODE),
            'change_preview' => json_encode($row['change_preview'] ?? [], JSON_UNESCAPED_UNICODE),
        ];
    }

    private function isEditableValidationRun(ImportRun $importRun): bool
    {
        return $importRun->run_type === ImportRun::TYPE_VALIDATION
            && $importRun->mode === ImportRun::MODE_VALIDATE_ONLY
            && $importRun->status === ImportRun::STATUS_COMPLETED;
    }

    private function hasActiveChildImport(ImportRun $importRun): bool
    {
        return ImportRun::query()
            ->where('parent_import_run_id', $importRun->id)
            ->whereIn('status', [ImportRun::STATUS_PENDING, ImportRun::STATUS_RUNNING, ImportRun::STATUS_CANCEL_REQUESTED])
            ->exists();
    }

    private function fullRestartRequiredResponse(string $message): array
    {
        return [
            'requires_full_restart' => true,
            'message' => $message,
            'rows' => collect(),
        ];
    }

    private function isOperationalOnlyMappingChange(array $currentMapping, array $nextMapping): bool
    {
        $sources = array_unique(array_merge(array_keys($currentMapping), array_keys($nextMapping)));

        foreach ($sources as $source) {
            $currentTarget = $currentMapping[$source] ?? 'ignore';
            $nextTarget = $nextMapping[$source] ?? 'ignore';

            if ($currentTarget === $nextTarget) {
                continue;
            }

            if (! $this->isOperationalMappingTarget($currentTarget) && $currentTarget !== 'ignore' && $currentTarget !== '') {
                return false;
            }

            if (! $this->isOperationalMappingTarget($nextTarget) && $nextTarget !== 'ignore' && $nextTarget !== '') {
                return false;
            }
        }

        return true;
    }

    private function isOperationalMappingTarget(string $target): bool
    {
        return in_array($target, self::OPERATIONAL_MAPPING_TARGETS, true);
    }

    private function applyOperationalDataFromSource(array $data, array $sourceData, array $mapping): array
    {
        foreach (self::OPERATIONAL_MAPPING_TARGETS as $target) {
            unset($data[$target]);
        }

        foreach ($mapping as $source => $target) {
            if (! $this->isOperationalMappingTarget((string) $target)) {
                continue;
            }

            $data[(string) $target] = $sourceData[$source] ?? '';
        }

        return $data;
    }

    private function legacyHiddenDataFromSource(array $sourceData, array $mapping): array
    {
        $data = [];

        foreach ($mapping as $source => $target) {
            if (in_array((string) $target, self::LEGACY_HIDDEN_MAPPING_TARGETS, true)) {
                $data[(string) $target] = $sourceData[$source] ?? null;
            }
        }

        return $data;
    }
}
