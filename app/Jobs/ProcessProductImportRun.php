<?php

namespace App\Jobs;

use App\Imports\ProductImport;
use App\Models\ImportRun;
use App\Services\ProductImportRunRowService;
use App\Services\ProductService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ProcessProductImportRun implements ShouldQueue
{
    use Queueable;

    private const IMPORT_CHUNK_SIZE = 500;

    private const VALIDATION_CHUNK_SIZE = 500;

    private const CANCEL_CHECK_ROWS = 10;

    private const CANCEL_CHECK_MICROSECONDS = 250000;

    private const RESULT_LIMIT = 100;

    public int $timeout = 1800;

    public function __construct(public int $importRunId)
    {
        $this->onConnection('product_imports');
        $this->onQueue((string) config('queue.connections.product_imports.queue', 'product-imports'));
    }

    public function handle(ProductService $productService, ?ProductImportRunRowService $rowService = null): void
    {
        $rowService ??= app(ProductImportRunRowService::class);

        $started = ImportRun::query()
            ->whereKey($this->importRunId)
            ->where('status', ImportRun::STATUS_PENDING)
            ->update([
                'status' => ImportRun::STATUS_RUNNING,
                'phase' => ImportRun::PHASE_STAGING,
                'started_at' => now(),
                'heartbeat_at' => now(),
            ]);

        $importRun = ImportRun::query()->find($this->importRunId);
        if (! $importRun) {
            return;
        }

        if ($started === 0) {
            if ($importRun->status === ImportRun::STATUS_CANCEL_REQUESTED) {
                $this->markCancelled($importRun, $importRun->processed_rows);
            }

            return;
        }

        if (! $importRun->shouldProcessFailedRows() && ! $this->sourceFileExists($importRun)) {
            $this->markFailed($importRun, "No se encontro el archivo almacenado para {$importRun->original_filename}.");

            return;
        }

        $import = null;

        try {
            $runStartedAt = microtime(true);
            $shouldStop = $this->cancellationChecker($importRun);
            $import = (new ProductImport($productService))
                ->forMode($importRun->mode ?? ImportRun::MODE_CREATE_UPDATE)
                ->forImportRun($importRun->id)
                ->withCancellationCallback($shouldStop);
            $isValidationRun = $importRun->mode === ImportRun::MODE_VALIDATE_ONLY
                || $importRun->run_type === ImportRun::TYPE_VALIDATION;

            $afterChunk = function (ProductImport $chunkImport, int $processedRows, int $totalRows) use ($importRun, $rowService, $shouldStop, $isValidationRun, $runStartedAt): bool {
                if ($isValidationRun) {
                    $this->persistPendingValidationRows($importRun, $chunkImport, $rowService);
                }

                $importRun->refresh();
                if ($importRun->status === ImportRun::STATUS_CANCEL_REQUESTED || $shouldStop($chunkImport)) {
                    $this->markCancelled($importRun, $processedRows, $chunkImport);

                    return false;
                }

                $importRun->update([
                    'phase' => $isValidationRun ? ImportRun::PHASE_VALIDATING : ImportRun::PHASE_IMPORTING,
                    'total_rows' => $totalRows,
                    'processed_rows' => $processedRows,
                    'success_count' => $chunkImport->successCount,
                    'error_count' => $chunkImport->errorCount,
                    'results' => array_slice($chunkImport->results, -self::RESULT_LIMIT),
                    'errors' => array_slice($chunkImport->errors, -self::RESULT_LIMIT),
                    'summary' => $this->summaryForImportRun($importRun, $chunkImport, [
                        'latest_results_limited_to' => self::RESULT_LIMIT,
                        'latest_errors_limited_to' => self::RESULT_LIMIT,
                        'metrics' => $this->metricsSummary($chunkImport, $processedRows, $runStartedAt),
                    ]),
                    'heartbeat_at' => now(),
                ]);
                $chunkImport->results = array_slice($chunkImport->results, -self::RESULT_LIMIT);

                if ($isValidationRun) {
                    $chunkImport->errors = array_slice($chunkImport->errors, -self::RESULT_LIMIT);
                }

                return true;
            };

            if (($importRun->summary['use_persisted_rows_snapshot'] ?? false) === true && $importRun->rows()->exists()) {
                $totalRows = $importRun->rows()->where('status', 'valid')->count();
                $importRun->update([
                    'phase' => ImportRun::PHASE_IMPORTING,
                    'total_rows' => $totalRows,
                    'processed_rows' => 0,
                    'heartbeat_at' => now(),
                ]);
                $rows = $rowService->claimAllImportableRows($importRun);
                $rowNumbers = collect($rows)->pluck('__row')->map(fn ($row): int => (int) $row)->all();
                $import->executeAtomic($rows);
                $rowService->synchronizeAtomicExecutionResultsForRows($importRun, $import, $rowNumbers);

                if (! $afterChunk($import, $import->processedCount, $totalRows)) {
                    return;
                }

                if ($import->errorCount > 0) {
                    throw new \RuntimeException('Archivo no importado: corrige los errores y vuelve a validar antes de importar.');
                }
            } elseif ($importRun->shouldProcessFailedRows()) {
                $rows = collect($importRun->failed_rows)->map(fn (array $row): array => $row['data'] ?? $row)->values()->all();
                $importRun->update([
                    'phase' => ImportRun::PHASE_IMPORTING,
                    'total_rows' => count($rows),
                    'processed_rows' => 0,
                    'heartbeat_at' => now(),
                ]);
                $import->executeAtomic($rows);
                if ($shouldStop($import)) {
                    $this->markCancelled($importRun, $import->processedCount, $import);

                    return;
                }
                if ($import->errorCount > 0) {
                    throw new \RuntimeException('Archivo no importado: corrige los errores y vuelve a validar antes de importar.');
                }
            } else {
                $path = Storage::disk('local')->path($importRun->stored_path);
                $selectedSheet = $import->resolveSelectedSheet($path, $importRun->sheet_index, $importRun->sheet_name, $importRun->mapping ?? []);
                $importRun->update([
                    'phase' => $isValidationRun ? ImportRun::PHASE_VALIDATING : ImportRun::PHASE_IMPORTING,
                    'total_rows' => (int) ($selectedSheet['row_count'] ?? 0),
                    'processed_rows' => 0,
                    'heartbeat_at' => now(),
                ]);

                if ($isValidationRun) {
                    $import->validateFileInChunks($path, self::VALIDATION_CHUNK_SIZE, $afterChunk, $importRun->sheet_index, $importRun->sheet_name, $importRun->mapping ?? []);
                } else {
                    $rows = [];
                    $offset = 0;

                    do {
                        $chunk = $import->fileRowsChunk($path, $offset, self::IMPORT_CHUNK_SIZE, $importRun->sheet_index, $importRun->sheet_name, $importRun->mapping ?? []);
                        $rows = array_merge($rows, $chunk['rows']);
                        $offset += count($chunk['rows']);
                    } while (($chunk['has_more'] ?? false) === true && $chunk['rows'] !== []);

                    $import->executeAtomic($rows);

                    if (! $afterChunk($import, $import->processedCount, count($rows))) {
                        return;
                    }

                    if ($import->errorCount > 0) {
                        throw new \RuntimeException('Archivo no importado: corrige los errores y vuelve a validar antes de importar.');
                    }
                }
            }

            $importRun->refresh();
            if ($importRun->status === ImportRun::STATUS_CANCELLED) {
                return;
            }

            if ($importRun->status === ImportRun::STATUS_CANCEL_REQUESTED || $shouldStop($import)) {
                $this->markCancelled($importRun, $import->processedCount, $import);

                return;
            }

            $this->persistValidationRows($importRun, $import, $rowService);

            $importRun->update([
                'status' => ImportRun::STATUS_COMPLETED,
                'phase' => ImportRun::PHASE_FINALIZING,
                'total_rows' => $import->processedCount,
                'processed_rows' => $import->processedCount,
                'success_count' => $import->successCount,
                'error_count' => $import->errorCount,
                'results' => array_slice($import->results, -self::RESULT_LIMIT),
                'errors' => array_slice($import->errors, -self::RESULT_LIMIT),
                'failed_rows' => array_values(array_filter($import->errors, fn (array $error): bool => ! empty($error['data']))),
                'summary' => $this->summaryForImportRun($importRun, $import, array_merge([
                    'latest_results_limited_to' => self::RESULT_LIMIT,
                    'latest_errors_limited_to' => self::RESULT_LIMIT,
                    'records' => $importRun->records()->selectRaw('action, count(*) as total')->groupBy('action')->pluck('total', 'action')->all(),
                    'source_file_deleted' => false,
                    'metrics' => $this->metricsSummary($import, $import->processedCount, $runStartedAt),
                ], $importRun->rows()->exists()
                    ? array_merge($rowService->summaryCounts($importRun), $rowService->groupingSummary($importRun))
                    : [])),
                'finished_at' => now(),
                'heartbeat_at' => now(),
            ]);
            $importRun->refresh();
            $this->recordActivity(
                $importRun,
                $importRun->run_type === ImportRun::TYPE_VALIDATION ? 'validation_completed' : 'import_completed',
                $importRun->run_type === ImportRun::TYPE_VALIDATION
                    ? 'Validacion de importacion completada.'
                    : 'Importacion de productos completada.'
            );
        } catch (\Throwable $e) {
            $this->markFailed($importRun, $e->getMessage(), $import);
        }
    }

    private function sourceFileExists(ImportRun $importRun): bool
    {
        return is_string($importRun->stored_path)
            && $importRun->stored_path !== ''
            && Storage::disk('local')->exists($importRun->stored_path);
    }

    private function markCancelled(ImportRun $importRun, int $processedRows, ?ProductImport $import = null): void
    {
        $importRun->update([
            'status' => ImportRun::STATUS_CANCELLED,
            'phase' => ImportRun::PHASE_FINALIZING,
            'processed_rows' => $processedRows,
            'success_count' => $import?->successCount ?? $importRun->success_count,
            'error_count' => $import?->errorCount ?? $importRun->error_count,
            'results' => $import === null ? ($importRun->results ?? []) : array_slice($import->results, -self::RESULT_LIMIT),
            'errors' => $import === null ? ($importRun->errors ?? []) : array_slice($import->errors, -self::RESULT_LIMIT),
            'summary' => array_merge(
                $importRun->summary ?? [],
                $import === null ? [] : $this->validationRowsSummary($importRun, $import),
                [
                    'source_file_deleted' => false,
                    'metrics' => $import === null ? ($importRun->summary['metrics'] ?? []) : $this->metricsSummary($import, $processedRows, (float) ($importRun->started_at?->timestamp ?? time())),
                ]
            ),
            'finished_at' => now(),
            'heartbeat_at' => now(),
        ]);
        $importRun->refresh();
        $this->recordActivity($importRun, 'cancelled', 'Importacion cancelada durante el procesamiento.');
    }

    private function cancellationChecker(ImportRun $importRun): callable
    {
        $lastCheckedAt = 0.0;
        $lastProcessedRows = -self::CANCEL_CHECK_ROWS;
        $cancelRequested = false;

        return function (ProductImport $import) use ($importRun, &$lastCheckedAt, &$lastProcessedRows, &$cancelRequested): bool {
            if ($cancelRequested) {
                return true;
            }

            $processedRows = $import->processedCount;
            $now = microtime(true);

            if (
                $processedRows - $lastProcessedRows < self::CANCEL_CHECK_ROWS
                && ($now - $lastCheckedAt) < (self::CANCEL_CHECK_MICROSECONDS / 1000000)
            ) {
                return false;
            }

            $lastCheckedAt = $now;
            $lastProcessedRows = $processedRows;

            $cancelRequested = ImportRun::query()
                ->whereKey($importRun->id)
                ->where('status', ImportRun::STATUS_CANCEL_REQUESTED)
                ->exists();

            return $cancelRequested;
        };
    }

    private function markFailed(ImportRun $importRun, string $message, ?ProductImport $import = null): void
    {
        $importRun->refresh();
        $partialPersistFailureMessage = null;
        if (
            $import !== null
            && ($importRun->mode === ImportRun::MODE_VALIDATE_ONLY || $importRun->run_type === ImportRun::TYPE_VALIDATION)
            && $import->validationRows !== []
        ) {
            try {
                app(ProductImportRunRowService::class)->persistValidationRowsChunk($importRun, $import->validationRows);
                $import->validationRows = [];
            } catch (\Throwable $persistException) {
                $partialPersistFailureMessage = $persistException->getMessage();
            }
        }
        $errors = $this->failureErrors($importRun, $message, $import);

        $importRun->update([
            'status' => ImportRun::STATUS_FAILED,
            'phase' => ImportRun::PHASE_FINALIZING,
            'processed_rows' => $import?->processedCount ?? $importRun->processed_rows,
            'success_count' => $import?->successCount ?? $importRun->success_count,
            'error_count' => $import?->errorCount ?? $importRun->error_count,
            'results' => $import === null ? ($importRun->results ?? []) : array_slice($import->results, -self::RESULT_LIMIT),
            'errors' => $errors,
            'failed_rows' => $import === null ? ($importRun->failed_rows ?? []) : array_values(array_filter($import->errors, fn (array $error): bool => ! empty($error['data']))),
            'summary' => array_merge(
                $importRun->summary ?? [],
                $import === null ? [] : $this->validationRowsSummary($importRun, $import),
                [
                    'source_file_deleted' => false,
                    'failure_message' => $message,
                ],
                $partialPersistFailureMessage === null ? [] : [
                    'partial_validation_rows_persist_failed' => true,
                    'partial_validation_rows_persist_failure_message' => $partialPersistFailureMessage,
                ],
            ),
            'finished_at' => now(),
            'heartbeat_at' => now(),
        ]);
        $importRun->refresh();
        $this->recordActivity(
            $importRun,
            $importRun->run_type === ImportRun::TYPE_VALIDATION ? 'validation_failed' : 'import_failed',
            $importRun->run_type === ImportRun::TYPE_VALIDATION
                ? 'Validacion de importacion fallida.'
                : 'Importacion de productos fallida.',
            ['message' => $message]
        );
    }

    private function failureErrors(ImportRun $importRun, string $message, ?ProductImport $import = null): array
    {
        $failureError = [
            'row' => 0,
            'label' => $importRun->original_filename,
            'error' => $message,
        ];

        if ($import === null || empty($import->errors)) {
            return [$failureError];
        }

        return array_slice(array_merge($import->errors, [$failureError]), -self::RESULT_LIMIT);
    }

    private function summaryForImportRun(ImportRun $importRun, ProductImport $import, array $summary): array
    {
        return array_merge(
            $importRun->summary ?? [],
            $summary,
            $import->referenceSummary(),
            $this->validationRowsSummary($importRun, $import),
        );
    }

    private function validationRowsSummary(ImportRun $importRun, ProductImport $import): array
    {
        if ($importRun->mode !== ImportRun::MODE_VALIDATE_ONLY && $importRun->run_type !== ImportRun::TYPE_VALIDATION) {
            return [];
        }

        $persistedRowsCount = $importRun->rows()->count();
        $validationRowsCount = $persistedRowsCount + count($import->validationRows);

        return [
            'validation_rows_count' => $validationRowsCount,
            'validation_rows_limited' => true,
            'rows_revision' => (int) ($importRun->summary['rows_revision'] ?? 0),
            'validated_rows_revision' => (int) ($importRun->summary['rows_revision'] ?? 0),
            'rows_dirty' => false,
            'full_revalidation_status' => 'completed',
        ];
    }

    private function persistValidationRows(ImportRun $importRun, ProductImport $import, ProductImportRunRowService $rowService): void
    {
        if ($importRun->mode !== ImportRun::MODE_VALIDATE_ONLY && $importRun->run_type !== ImportRun::TYPE_VALIDATION) {
            return;
        }

        if ($import->validationRows === []) {
            return;
        }

        if ($importRun->rows()->exists()) {
            $rowService->persistValidationRowsChunk($importRun, $import->validationRows);
            $import->validationRows = [];

            return;
        }

        $rowService->persistValidationRows($importRun, $import);
        $import->validationRows = [];
    }

    private function persistPendingValidationRows(ImportRun $importRun, ProductImport $import, ProductImportRunRowService $rowService): void
    {
        if ($import->validationRows === []) {
            return;
        }

        $rowService->persistValidationRowsChunk($importRun, $import->validationRows);
        $import->validationRows = [];
        $import->results = array_slice($import->results, -self::RESULT_LIMIT);
        $import->errors = array_slice($import->errors, -self::RESULT_LIMIT);
    }

    private function metricsSummary(ProductImport $import, int $processedRows, float $startedAt): array
    {
        $durationSeconds = max(0.001, microtime(true) - $startedAt);

        return [
            'duration_seconds' => round($durationSeconds, 3),
            'rows_per_second' => round($processedRows / $durationSeconds, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            'latest_result_count' => count($import->results),
            'latest_error_count' => count($import->errors),
            'last_heartbeat_at' => now()->toISOString(),
        ];
    }

    private function recordActivity(ImportRun $importRun, string $event, string $description, array $properties = []): void
    {
        $summary = $importRun->summary ?? [];

        activity('product_import')
            ->causedBy($importRun->user)
            ->performedOn($importRun)
            ->event($event)
            ->withProperties(array_merge([
                'import_run_id' => $importRun->id,
                'parent_import_run_id' => $importRun->parent_import_run_id,
                'user_id' => $importRun->user_id,
                'entity' => $importRun->entity,
                'run_type' => $importRun->run_type,
                'mode' => $importRun->mode,
                'status' => $importRun->status,
                'rows_revision' => (int) ($summary['rows_revision'] ?? $summary['source_rows_revision'] ?? 0),
                'rows_count' => (int) $importRun->total_rows,
                'error_count' => (int) $importRun->error_count,
                'source' => ($summary['force_without_preview'] ?? false) ? 'force_without_preview' : 'process_product_import_run',
            ], $properties))
            ->log($description);
    }
}
