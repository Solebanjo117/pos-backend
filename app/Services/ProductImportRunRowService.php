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

    private const PROCESSING_LEASE_MINUTES = 5;

    public function __construct(private readonly ProductService $productService) {}

    public function persistValidationRows(ImportRun $importRun, ProductImport $import): void
    {
        $this->replaceRows($importRun, $import->validationRows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $stagingRows
     */
    public function appendPendingValidationRows(ImportRun $importRun, array $stagingRows): void
    {
        foreach (array_chunk($stagingRows, 500) as $chunk) {
            $now = now();
            $rows = array_map(fn (array $row): array => $this->pendingValidationRowAttributes($importRun, $row, $now), $chunk);

            ImportRunRow::query()->insert($rows);
        }
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
        $sourceRun->rows()
            ->where('status', 'valid')
            ->orderBy('id')
            ->chunkById(100, function (Collection $rows) use ($targetRun): void {
                $now = now();
                ImportRunRow::query()->insert($rows->map(fn (ImportRunRow $row): array => [
                    'import_run_id' => $targetRun->id,
                    'row_number' => $row->row_number,
                    'sheet_name' => $row->sheet_name,
                    'status' => $row->status,
                    'action_type' => $row->action_type,
                    'execution_status' => $row->execution_status,
                    'label' => $row->label,
                    'search_text' => $row->search_text,
                    'product_group_key' => $row->product_group_key,
                    'variant_key' => $row->variant_key,
                    'conflict_key' => $row->conflict_key,
                    'brand_key' => $row->brand_key,
                    'category_key' => $row->category_key,
                    'is_edited' => $row->is_edited,
                    'validated_at' => $row->validated_at,
                    'executed_at' => $row->executed_at,
                    'execution_error' => $row->execution_error,
                    'data' => $this->rawJsonAttribute($row, 'data'),
                    'source_data' => $this->rawJsonAttribute($row, 'source_data'),
                    'errors' => $this->rawJsonAttribute($row, 'errors'),
                    'warnings' => $this->rawJsonAttribute($row, 'warnings'),
                    'conflicts' => $this->rawJsonAttribute($row, 'conflicts'),
                    'change_preview' => $this->rawJsonAttribute($row, 'change_preview'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    private function rawJsonAttribute(ImportRunRow $row, string $attribute): string
    {
        $value = $row->getRawOriginal($attribute);

        return is_string($value)
            ? $value
            : json_encode($value ?? [], JSON_UNESCAPED_UNICODE);
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

    /**
     * @return array{affected_rows_count: int, fallback_full_revalidation: bool, counts: array<string, mixed>}
     */
    public function revalidateReferenceRows(ImportRun $importRun, ProductImport $import, array $brands = [], array $categories = []): array
    {
        $keys = $this->referenceHashKeys($brands, $categories);

        if ($keys['brands'] === [] && $keys['categories'] === []) {
            return [
                'affected_rows_count' => 0,
                'fallback_full_revalidation' => false,
                'counts' => $this->summaryCounts($importRun),
            ];
        }

        if ($this->requiresReferenceRevalidationFallback($importRun, $keys)) {
            return [
                'affected_rows_count' => 0,
                'fallback_full_revalidation' => true,
                'counts' => $this->summaryCounts($importRun),
            ];
        }

        $rows = $importRun->rows()
            ->where(function ($query) use ($keys): void {
                if ($keys['brands'] !== []) {
                    $query->whereIn('brand_key', $keys['brands']);
                }

                if ($keys['categories'] !== []) {
                    $method = $keys['brands'] === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('category_key', $keys['categories']);
                }
            })
            ->select(['id', 'row_number', 'data', 'source_data'])
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'affected_rows_count' => 0,
                'fallback_full_revalidation' => false,
                'counts' => $this->summaryCounts($importRun),
            ];
        }

        $payloads = $rows
            ->map(fn (ImportRunRow $row): array => array_merge(
                $this->legacyHiddenDataFromSource($row->source_data ?? [], $importRun->mapping ?? []),
                $row->data ?? [],
                [
                    '__row' => $row->row_number,
                    '__source_data' => $row->source_data ?? [],
                ]
            ))
            ->all();

        $import->validateRowsWithDuplicateKeys($payloads, $import->duplicateRowKeysForRows($payloads));
        $this->persistValidationRowsChunk($importRun, $import->validationRows, true);
        $import->validationRows = [];

        return [
            'affected_rows_count' => $rows->count(),
            'fallback_full_revalidation' => false,
            'counts' => $this->summaryCounts($importRun->refresh()),
        ];
    }

    /**
     * @param  array{brands: array<int, string>, categories: array<int, string>}  $keys
     */
    private function requiresReferenceRevalidationFallback(ImportRun $importRun, array $keys): bool
    {
        if (
            $keys['brands'] !== []
            && $this->rowsWithoutReferenceKeyMatch($importRun, 'brand_key', 'br', $keys['brands'])
        ) {
            return true;
        }

        return $keys['categories'] !== []
            && $this->rowsWithoutReferenceKeyMatch($importRun, 'category_key', 'cat', $keys['categories']);
    }

    /**
     * @param  array<int, string>  $requestedKeys
     */
    private function rowsWithoutReferenceKeyMatch(ImportRun $importRun, string $column, string $prefix, array $requestedKeys): bool
    {
        $matched = false;
        $requested = array_flip($requestedKeys);

        $importRun->rows()
            ->whereNull($column)
            ->select(['id', 'data'])
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows) use (&$matched, $prefix, $requested): bool {
                foreach ($rows as $row) {
                    $data = $row->data ?? [];
                    $value = $prefix === 'br'
                        ? $this->brandReferenceValue($data)
                        : $this->categoryReferenceValue($data);
                    $key = $this->referenceHashKey($prefix, $value);

                    if ($key !== null && isset($requested[$key])) {
                        $matched = true;

                        return false;
                    }
                }

                return true;
            });

        return $matched;
    }

    public function eachExecutableRowChunk(ImportRun $importRun, int $chunkSize, callable $callback): void
    {
        $importRun->rows()
            ->select(['id', 'row_number', 'data', 'source_data'])
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $rows) use ($callback, $importRun): bool {
                $shouldContinue = $callback($rows
                    ->map(fn (ImportRunRow $row): array => array_merge(
                        $this->legacyHiddenDataFromSource($row->source_data ?? [], $importRun->mapping ?? []),
                        $row->data ?? [],
                        [
                            '__row' => $row->row_number,
                            '__source_data' => $row->source_data ?? [],
                        ]
                    ))
                    ->all());

                return $shouldContinue !== false;
            });
    }

    public function eachImportableRowChunk(ImportRun $importRun, int $chunkSize, callable $callback): void
    {
        $importRun->rows()
            ->where('status', 'valid')
            ->select(['id', 'row_number', 'data', 'source_data'])
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $rows) use ($callback, $importRun): bool {
                $shouldContinue = $callback($rows
                    ->map(fn (ImportRunRow $row): array => array_merge(
                        $this->legacyHiddenDataFromSource($row->source_data ?? [], $importRun->mapping ?? []),
                        $row->data ?? [],
                        [
                            '__row' => $row->row_number,
                            '__source_data' => $row->source_data ?? [],
                        ]
                    ))
                    ->all());

                return $shouldContinue !== false;
            });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function claimAllImportableRows(ImportRun $importRun): array
    {
        return DB::transaction(function () use ($importRun): array {
            $staleBefore = now()->subMinutes(self::PROCESSING_LEASE_MINUTES);
            $rows = $importRun->rows()
                ->where('status', 'valid')
                ->select(['id', 'row_number', 'data', 'source_data', 'execution_status', 'updated_at'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return [];
            }

            $alreadyExecuted = $rows->contains(fn (ImportRunRow $row): bool => in_array($row->execution_status, ['success', 'failed'], true));
            if ($alreadyExecuted) {
                return [];
            }

            $activeProcessing = $rows->contains(
                fn (ImportRunRow $row): bool => $row->execution_status === 'processing'
                    && $row->updated_at !== null
                    && $row->updated_at->greaterThanOrEqualTo($staleBefore)
            );
            if ($activeProcessing) {
                return [];
            }

            ImportRunRow::query()
                ->whereKey($rows->pluck('id')->all())
                ->update([
                    'execution_status' => 'processing',
                    'updated_at' => now(),
                ]);

            return $rows
                ->map(fn (ImportRunRow $row): array => array_merge(
                    $this->legacyHiddenDataFromSource($row->source_data ?? [], $importRun->mapping ?? []),
                    $row->data ?? [],
                    [
                        '__row' => $row->row_number,
                        '__source_data' => $row->source_data ?? [],
                    ]
                ))
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function nextPendingImportableRows(ImportRun $importRun, int $chunkSize): array
    {
        return $importRun->rows()
            ->where('status', 'valid')
            ->whereNull('execution_status')
            ->select(['id', 'row_number', 'data', 'source_data'])
            ->orderBy('id')
            ->limit($chunkSize)
            ->get()
            ->map(fn (ImportRunRow $row): array => array_merge(
                $this->legacyHiddenDataFromSource($row->source_data ?? [], $importRun->mapping ?? []),
                $row->data ?? [],
                [
                    '__row' => $row->row_number,
                    '__source_data' => $row->source_data ?? [],
                ]
            ))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function claimPendingImportableRows(ImportRun $importRun, int $chunkSize): array
    {
        return DB::transaction(function () use ($importRun, $chunkSize): array {
            $staleBefore = now()->subMinutes(self::PROCESSING_LEASE_MINUTES);
            $seedRows = $importRun->rows()
                ->where('status', 'valid')
                ->where(function ($query) use ($staleBefore): void {
                    $query->whereNull('execution_status')
                        ->orWhere(function ($query) use ($staleBefore): void {
                            $query->where('execution_status', 'processing')
                                ->where('updated_at', '<', $staleBefore);
                        });
                })
                ->select(['id', 'product_group_key'])
                ->orderBy('id')
                ->limit($chunkSize)
                ->lockForUpdate()
                ->get();

            if ($seedRows->isEmpty()) {
                return [];
            }

            $groupKeys = $seedRows
                ->pluck('product_group_key')
                ->filter(fn ($key): bool => is_string($key) && trim($key) !== '')
                ->unique()
                ->values()
                ->all();
            $ungroupedRowIds = $seedRows
                ->filter(fn (ImportRunRow $row): bool => ! is_string($row->product_group_key) || trim($row->product_group_key) === '')
                ->pluck('id')
                ->all();

            $claimedRows = $importRun->rows()
                ->where('status', 'valid')
                ->where(function ($query) use ($staleBefore): void {
                    $query->whereNull('execution_status')
                        ->orWhere(function ($query) use ($staleBefore): void {
                            $query->where('execution_status', 'processing')
                                ->where('updated_at', '<', $staleBefore);
                        });
                })
                ->where(function ($query) use ($groupKeys, $ungroupedRowIds): void {
                    if ($groupKeys !== []) {
                        $query->whereIn('product_group_key', $groupKeys);
                    }

                    if ($ungroupedRowIds !== []) {
                        $method = $groupKeys === [] ? 'whereIn' : 'orWhereIn';
                        $query->{$method}('id', $ungroupedRowIds);
                    }
                })
                ->select(['id', 'row_number', 'data', 'source_data'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($claimedRows->isEmpty()) {
                return [];
            }

            $now = now();
            ImportRunRow::query()
                ->whereKey($claimedRows->pluck('id')->all())
                ->update([
                    'execution_status' => 'processing',
                    'updated_at' => $now,
                ]);

            return $claimedRows
                ->map(fn (ImportRunRow $row): array => array_merge(
                    $this->legacyHiddenDataFromSource($row->source_data ?? [], $importRun->mapping ?? []),
                    $row->data ?? [],
                    [
                        '__row' => $row->row_number,
                        '__source_data' => $row->source_data ?? [],
                    ]
                ))
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function claimPendingValidationRows(ImportRun $importRun, int $chunkSize): array
    {
        return DB::transaction(function () use ($importRun, $chunkSize): array {
            $staleBefore = now()->subMinutes(self::PROCESSING_LEASE_MINUTES);
            $seedRows = $importRun->rows()
                ->where(function ($query) use ($staleBefore): void {
                    $query->where('status', 'pending_validation')
                        ->orWhere(function ($query) use ($staleBefore): void {
                            $query->where('status', 'validation_processing')
                                ->where('updated_at', '<', $staleBefore);
                        });
                })
                ->select(['id', 'product_group_key'])
                ->orderBy('id')
                ->limit($chunkSize)
                ->lockForUpdate()
                ->get();

            if ($seedRows->isEmpty()) {
                return [];
            }

            $groupKeys = $seedRows
                ->pluck('product_group_key')
                ->filter(fn ($key): bool => is_string($key) && trim($key) !== '')
                ->unique()
                ->values()
                ->all();
            $ungroupedRowIds = $seedRows
                ->filter(fn (ImportRunRow $row): bool => ! is_string($row->product_group_key) || trim($row->product_group_key) === '')
                ->pluck('id')
                ->all();

            $claimedRows = $importRun->rows()
                ->where(function ($query) use ($staleBefore): void {
                    $query->where('status', 'pending_validation')
                        ->orWhere(function ($query) use ($staleBefore): void {
                            $query->where('status', 'validation_processing')
                                ->where('updated_at', '<', $staleBefore);
                        });
                })
                ->where(function ($query) use ($groupKeys, $ungroupedRowIds): void {
                    if ($groupKeys !== []) {
                        $query->whereIn('product_group_key', $groupKeys);
                    }

                    if ($ungroupedRowIds !== []) {
                        $method = $groupKeys === [] ? 'whereIn' : 'orWhereIn';
                        $query->{$method}('id', $ungroupedRowIds);
                    }
                })
                ->select(['id', 'row_number', 'data', 'source_data', 'conflict_key'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($claimedRows->isEmpty()) {
                return [];
            }

            $now = now();
            ImportRunRow::query()
                ->whereKey($claimedRows->pluck('id')->all())
                ->update([
                    'status' => 'validation_processing',
                    'updated_at' => $now,
                ]);

            return $claimedRows
                ->map(fn (ImportRunRow $row): array => array_merge(
                    $this->legacyHiddenDataFromSource($row->source_data ?? [], $importRun->mapping ?? []),
                    $row->data ?? [],
                    [
                        '__row' => $row->row_number,
                        '__source_data' => $row->source_data ?? [],
                        '__import_run_row_conflict_key' => $row->conflict_key,
                    ]
                ))
                ->all();
        });
    }

    public function hasPendingImportableRows(ImportRun $importRun): bool
    {
        $staleBefore = now()->subMinutes(self::PROCESSING_LEASE_MINUTES);

        return $importRun->rows()
            ->where('status', 'valid')
            ->where(function ($query) use ($staleBefore): void {
                $query->whereNull('execution_status')
                    ->orWhere(function ($query) use ($staleBefore): void {
                        $query->where('execution_status', 'processing')
                            ->where('updated_at', '<', $staleBefore);
                    });
            })
            ->exists();
    }

    public function hasUnfinishedImportableRows(ImportRun $importRun): bool
    {
        return $importRun->rows()
            ->where('status', 'valid')
            ->where(function ($query): void {
                $query->whereNull('execution_status')
                    ->orWhere('execution_status', 'processing');
            })
            ->exists();
    }

    public function hasUnfinishedValidationRows(ImportRun $importRun): bool
    {
        return $importRun->rows()
            ->whereIn('status', ['pending_validation', 'validation_processing'])
            ->exists();
    }

    public function validatedRowsCount(ImportRun $importRun): int
    {
        return $importRun->rows()
            ->whereIn('status', ['valid', 'error'])
            ->count();
    }

    public function totalRowsCount(ImportRun $importRun): int
    {
        return $importRun->rows()->count();
    }

    public function validationErrorRowsCount(ImportRun $importRun): int
    {
        return $importRun->rows()
            ->where('status', 'error')
            ->count();
    }

    public function validationSuccessRowsCount(ImportRun $importRun): int
    {
        return $importRun->rows()
            ->where('status', 'valid')
            ->count();
    }

    public function executedImportableRowsCount(ImportRun $importRun): int
    {
        return $importRun->rows()
            ->whereIn('execution_status', ['success', 'failed'])
            ->count();
    }

    /**
     * @param  array<int, int>  $rowNumbers
     */
    public function synchronizeExecutionResultsForRows(ImportRun $importRun, ProductImport $import, array $rowNumbers): void
    {
        if ($rowNumbers === []) {
            return;
        }

        $errorsByRow = collect($import->errors)
            ->whereIn('row', $rowNumbers)
            ->keyBy('row');

        $importRun->rows()
            ->whereIn('row_number', $rowNumbers)
            ->where('status', 'valid')
            ->update([
                'execution_status' => 'success',
                'executed_at' => now(),
                'execution_error' => null,
                'errors' => json_encode([], JSON_UNESCAPED_UNICODE),
                'conflicts' => json_encode([], JSON_UNESCAPED_UNICODE),
            ]);

        if ($errorsByRow->isEmpty()) {
            return;
        }

        $importRun->rows()
            ->whereIn('row_number', $errorsByRow->keys())
            ->select(['id', 'row_number', 'change_preview'])
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows) use ($errorsByRow): void {
                foreach ($rows as $row) {
                    $error = $errorsByRow->get($row->row_number);
                    $row->update([
                        'status' => 'error',
                        'execution_status' => 'failed',
                        'execution_error' => $error['error'],
                        'errors' => [$error['error']],
                        'conflicts' => $error['conflicts'] ?? [],
                        'change_preview' => $error['change_preview'] ?? $row->change_preview ?? [],
                    ]);
                }
            });
    }

    /**
     * @param  array<int, int>  $rowNumbers
     */
    public function synchronizeAtomicExecutionResultsForRows(ImportRun $importRun, ProductImport $import, array $rowNumbers): void
    {
        if ($rowNumbers === []) {
            return;
        }

        if ($import->errorCount <= 0) {
            $this->synchronizeExecutionResultsForRows($importRun, $import, $rowNumbers);

            return;
        }

        $errorsByRow = collect($import->errors)
            ->whereIn('row', $rowNumbers)
            ->keyBy('row');

        $importRun->rows()
            ->whereIn('row_number', $rowNumbers)
            ->where('status', 'valid')
            ->update([
                'execution_status' => null,
                'executed_at' => null,
                'execution_error' => null,
            ]);

        if ($errorsByRow->isEmpty()) {
            return;
        }

        $importRun->rows()
            ->whereIn('row_number', $errorsByRow->keys())
            ->select(['id', 'row_number', 'change_preview'])
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows) use ($errorsByRow): void {
                foreach ($rows as $row) {
                    $error = $errorsByRow->get($row->row_number);
                    $row->update([
                        'status' => 'error',
                        'execution_status' => 'failed',
                        'execution_error' => $error['error'],
                        'errors' => [$error['error']],
                        'conflicts' => $error['conflicts'] ?? [],
                        'change_preview' => $error['change_preview'] ?? $row->change_preview ?? [],
                    ]);
                }
            });
    }

    public function synchronizeValidationResultsForRows(ImportRun $importRun, ProductImport $import): void
    {
        if ($import->validationRows === []) {
            return;
        }

        $rowNumbers = collect($import->validationRows)
            ->map(fn (array $validationRow): int => (int) ($validationRow['row'] ?? 0))
            ->filter(fn (int $rowNumber): bool => $rowNumber > 0)
            ->unique()
            ->values();

        if ($rowNumbers->isEmpty()) {
            return;
        }

        $existingRows = $importRun->rows()
            ->whereIn('row_number', $rowNumbers)
            ->get(['row_number', 'source_data', 'is_edited'])
            ->keyBy('row_number');

        $now = now();
        $rows = [];
        foreach ($import->validationRows as $validationRow) {
            $rowNumber = (int) ($validationRow['row'] ?? 0);
            $existingRow = $existingRows->get($rowNumber);

            if (! $existingRow instanceof ImportRunRow) {
                continue;
            }

            if (($validationRow['source_data'] ?? null) === null && $existingRow->source_data !== null) {
                $validationRow['source_data'] = $existingRow->source_data;
            }

            $rows[] = $this->validationRowAttributes($importRun, $validationRow, (bool) $existingRow->is_edited, $now);
        }

        if ($rows === []) {
            return;
        }

        ImportRunRow::query()->upsert(
            $rows,
            ['import_run_id', 'row_number'],
            [
                'sheet_name',
                'status',
                'action_type',
                'execution_status',
                'label',
                'search_text',
                'product_group_key',
                'variant_key',
                'conflict_key',
                'brand_key',
                'category_key',
                'is_edited',
                'validated_at',
                'executed_at',
                'execution_error',
                'data',
                'source_data',
                'errors',
                'warnings',
                'conflicts',
                'change_preview',
                'updated_at',
            ]
        );
    }

    public function duplicateValidationConflictCounts(ImportRun $importRun): array
    {
        $counts = [];

        $importRun->rows()
            ->select(['id', 'data'])
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows) use (&$counts): void {
                foreach ($rows as $row) {
                    $data = $row->data ?? [];
                    $category = $this->normalizeImportKey((string) ($data['categoria'] ?? ''));
                    $name = $this->normalizeImportKey((string) ($data['nombre'] ?? ''));

                    if ($category === '' || $name === '') {
                        continue;
                    }

                    $productKey = $category.'|'.$name;
                    $variantPreview = is_array($data['__variant_preview'] ?? null)
                        ? $data['__variant_preview']
                        : null;
                    $variantKey = $this->variantGroupingKey($data, $variantPreview);
                    $conflictKey = $productKey.'|'.$variantKey;
                    $counts[$conflictKey] = (int) ($counts[$conflictKey] ?? 0) + 1;
                }
            });

        return $counts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function duplicateValidationConflictCountsForRows(ImportRun $importRun, array $rows): array
    {
        $targetConflictKeys = collect($rows)
            ->map(fn (array $row): string => $this->validationConflictKeyFromData($row))
            ->filter(fn (string $key): bool => $key !== '')
            ->unique()
            ->values()
            ->all();

        if ($targetConflictKeys === []) {
            return [];
        }

        $targetLookup = array_fill_keys($targetConflictKeys, true);
        $counts = [];
        $storedConflictKeys = collect($rows)
            ->pluck('__import_run_row_conflict_key')
            ->filter(fn ($key): bool => is_string($key) && trim($key) !== '')
            ->unique()
            ->values()
            ->all();

        $query = $importRun->rows()
            ->select(['id', 'data'])
            ->orderBy('id');

        if ($storedConflictKeys !== []) {
            $query->whereIn('conflict_key', $storedConflictKeys);
        }

        $query->chunkById(500, function (Collection $rows) use (&$counts, $targetLookup): void {
            foreach ($rows as $row) {
                $conflictKey = $this->validationConflictKeyFromData($row->data ?? []);

                if ($conflictKey === '' || ! isset($targetLookup[$conflictKey])) {
                    continue;
                }

                $counts[$conflictKey] = (int) ($counts[$conflictKey] ?? 0) + 1;
            }
        });

        return $counts;
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
        if ($validationRows === []) {
            return;
        }

        $editedRows = $preserveEdited
            ? $importRun->rows()->whereIn('row_number', collect($validationRows)->pluck('row'))->pluck('is_edited', 'row_number')
            : collect();

        $now = now();
        $rows = array_map(fn (array $validationRow): array => $this->validationRowAttributes(
            $importRun,
            $validationRow,
            (bool) ($editedRows[(int) ($validationRow['row'] ?? 0)] ?? false),
            $now
        ), $validationRows);

        ImportRunRow::query()->upsert(
            $rows,
            ['import_run_id', 'row_number'],
            [
                'sheet_name',
                'status',
                'action_type',
                'execution_status',
                'label',
                'search_text',
                'product_group_key',
                'variant_key',
                'conflict_key',
                'brand_key',
                'category_key',
                'is_edited',
                'validated_at',
                'executed_at',
                'execution_error',
                'data',
                'source_data',
                'errors',
                'warnings',
                'conflicts',
                'change_preview',
                'updated_at',
            ]
        );
    }

    public function synchronizeExecutionErrors(ImportRun $importRun, ProductImport $import): void
    {
        $errorsByRow = collect($import->errors)->keyBy('row');

        $importRun->rows()->where('status', 'valid')->update([
            'status' => 'valid',
            'execution_status' => 'success',
            'executed_at' => now(),
            'execution_error' => null,
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
                        'execution_status' => 'failed',
                        'execution_error' => $error['error'],
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

    /**
     * @return array{productos_estimados: int, variantes_estimadas: int}
     */
    public function estimatedGroupingCounts(ImportRun $importRun): array
    {
        $productCount = $importRun->rows()
            ->whereNotNull('product_group_key')
            ->distinct()
            ->count('product_group_key');

        $variantCount = $importRun->rows()
            ->whereNotNull('conflict_key')
            ->distinct()
            ->count('conflict_key');

        if ($variantCount === 0) {
            $variantCount = $importRun->rows()
                ->whereNotNull('variant_key')
                ->distinct()
                ->count('variant_key');
        }

        return [
            'productos_estimados' => $productCount,
            'variantes_estimadas' => $variantCount,
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

    private function validationConflictKeyFromData(array $data): string
    {
        $category = $this->normalizeImportKey((string) ($data['categoria'] ?? ''));
        $name = $this->normalizeImportKey((string) ($data['nombre'] ?? ''));

        if ($category === '' || $name === '') {
            return '';
        }

        $variantPreview = is_array($data['__variant_preview'] ?? null)
            ? $data['__variant_preview']
            : null;

        return $category.'|'.$name.'|'.$this->variantGroupingKey($data, $variantPreview);
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

    private function pendingValidationRowAttributes(ImportRun $importRun, array $row, Carbon $now): array
    {
        $data = $row['data'] ?? [];
        $identity = $this->validationIdentityColumns($data);

        return [
            'import_run_id' => $importRun->id,
            'row_number' => (int) ($row['row'] ?? 0),
            'sheet_name' => $importRun->sheet_name,
            'status' => 'pending_validation',
            'action_type' => null,
            'execution_status' => null,
            'label' => $data['nombre'] ?? null,
            'search_text' => $this->searchText($data),
            'product_group_key' => $identity['product_group_key'],
            'variant_key' => $identity['variant_key'],
            'conflict_key' => $identity['conflict_key'],
            'brand_key' => $identity['brand_key'],
            'category_key' => $identity['category_key'],
            'is_edited' => false,
            'validated_at' => null,
            'executed_at' => null,
            'execution_error' => null,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'source_data' => json_encode($row['source_data'] ?? null, JSON_UNESCAPED_UNICODE),
            'errors' => json_encode([], JSON_UNESCAPED_UNICODE),
            'warnings' => json_encode($row['warnings'] ?? [], JSON_UNESCAPED_UNICODE),
            'conflicts' => json_encode([], JSON_UNESCAPED_UNICODE),
            'change_preview' => json_encode([], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ];
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
        $identity = $this->validationIdentityColumns($data);

        return [
            'status' => ($row['is_valid'] ?? false) ? 'valid' : 'error',
            'action_type' => $row['action_type'] ?? null,
            'execution_status' => null,
            'label' => $row['label'] ?? ($data['nombre'] ?? null),
            'search_text' => $this->searchText($data),
            'product_group_key' => $identity['product_group_key'],
            'variant_key' => $identity['variant_key'],
            'conflict_key' => $identity['conflict_key'],
            'brand_key' => $identity['brand_key'],
            'category_key' => $identity['category_key'],
            'is_edited' => $isEdited,
            'validated_at' => now(),
            'executed_at' => null,
            'execution_error' => null,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'source_data' => json_encode($row['source_data'] ?? null, JSON_UNESCAPED_UNICODE),
            'errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
            'warnings' => json_encode($row['warnings'] ?? [], JSON_UNESCAPED_UNICODE),
            'conflicts' => json_encode($conflicts, JSON_UNESCAPED_UNICODE),
            'change_preview' => json_encode($row['change_preview'] ?? [], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @return array{product_group_key: ?string, variant_key: ?string, conflict_key: ?string, brand_key: ?string, category_key: ?string}
     */
    private function validationIdentityColumns(array $data): array
    {
        $productGroupKey = $this->nonEmptyString($data['__product_group_key'] ?? $data['__product_key'] ?? null);
        $variantPreview = is_array($data['__variant_preview'] ?? null)
            ? $data['__variant_preview']
            : null;
        $variantKey = $this->nonEmptyString($variantPreview['variant_key'] ?? null)
            ?? $this->nonEmptyString($this->variantGroupingKey($data, $variantPreview));
        $conflictKey = $productGroupKey !== null && $variantKey !== null
            ? $productGroupKey.'|'.$variantKey
            : null;

        return [
            'product_group_key' => $this->technicalIdentityKey('pg', $productGroupKey),
            'variant_key' => $this->technicalIdentityKey('vk', $variantKey),
            'conflict_key' => $this->technicalIdentityKey('ck', $conflictKey),
            'brand_key' => $this->referenceHashKey('br', $this->brandReferenceValue($data)),
            'category_key' => $this->referenceHashKey('cat', $this->categoryReferenceValue($data)),
        ];
    }

    /**
     * @return array{brands: array<int, string>, categories: array<int, string>}
     */
    public function referenceHashKeys(array $brands = [], array $categories = []): array
    {
        return [
            'brands' => collect($brands)
                ->map(fn (mixed $brand): ?string => $this->referenceHashKey('br', $this->nonEmptyString($brand)))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'categories' => collect($categories)
                ->map(fn (mixed $category): ?string => $this->referenceHashKey('cat', $this->nonEmptyString($category)))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    private function referenceHashKey(string $prefix, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $prefix.':'.hash('sha256', $this->normalizeImportKey($value));
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

    private function technicalIdentityKey(string $prefix, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $prefix.':'.hash('sha256', $value);
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
