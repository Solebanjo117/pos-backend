<?php

namespace App\Services;

use App\Imports\ProductImport;
use App\Models\ImportRun;
use Illuminate\Support\Facades\Storage;

class ProductImportRunChunkProcessor
{
    private const CHUNK_SIZE = 500;

    private const RESULT_LIMIT = 100;

    public function __construct(
        private readonly ProductService $productService,
        private readonly ProductImportRunRowService $rowService,
    ) {}

    /**
     * @return array{import_run: ImportRun, processed_rows: int, has_more: bool}
     */
    public function process(ImportRun $importRun, int $chunkSize = self::CHUNK_SIZE): array
    {
        $importRun->refresh();

        if ($importRun->isTerminal()) {
            abort(409, 'La importacion ya finalizo y no admite mas chunks.');
        }

        if ($importRun->status === ImportRun::STATUS_CANCEL_REQUESTED) {
            $this->markCancelled($importRun, (int) $importRun->processed_rows);

            return [
                'import_run' => $importRun->refresh(),
                'processed_rows' => 0,
                'has_more' => false,
            ];
        }

        $this->startIfPending($importRun);

        if (! $importRun->shouldProcessFailedRows() && ! $this->sourceFileExists($importRun)) {
            $this->markFailed($importRun, "No se encontro el archivo almacenado para {$importRun->original_filename}.");

            return [
                'import_run' => $importRun->refresh(),
                'processed_rows' => 0,
                'has_more' => false,
            ];
        }

        $runStartedAt = microtime(true);
        $import = $this->newImport($importRun);

        try {
            $result = $this->processNextChunk($importRun, $import, $chunkSize, $runStartedAt);
        } catch (\Throwable $e) {
            $this->markFailed($importRun, $e->getMessage(), $import);

            return [
                'import_run' => $importRun->refresh(),
                'processed_rows' => 0,
                'has_more' => false,
            ];
        }

        $importRun->refresh();
        if ($importRun->status === ImportRun::STATUS_CANCEL_REQUESTED) {
            $this->markCancelled($importRun, (int) $importRun->processed_rows, $import);

            return [
                'import_run' => $importRun->refresh(),
                'processed_rows' => $result['processed_rows'],
                'has_more' => false,
            ];
        }

        if (! $result['has_more'] && ! $importRun->isTerminal()) {
            $this->markCompleted($importRun, $import, $runStartedAt);
        }

        return [
            'import_run' => $importRun->refresh(),
            'processed_rows' => $result['processed_rows'],
            'has_more' => $result['has_more'],
        ];
    }

    private function startIfPending(ImportRun $importRun): void
    {
        if ($importRun->status !== ImportRun::STATUS_PENDING) {
            return;
        }

        ImportRun::query()
            ->whereKey($importRun->id)
            ->where('status', ImportRun::STATUS_PENDING)
            ->update([
                'status' => ImportRun::STATUS_RUNNING,
                'phase' => ImportRun::PHASE_STAGING,
                'started_at' => $importRun->started_at ?? now(),
                'heartbeat_at' => now(),
            ]);

        $importRun->refresh();
    }

    /**
     * @return array{processed_rows: int, has_more: bool}
     */
    private function processNextChunk(ImportRun $importRun, ProductImport $import, int $chunkSize, float $runStartedAt): array
    {
        $isValidationRun = $this->isValidationRun($importRun);

        if (($importRun->summary['use_persisted_rows_snapshot'] ?? false) === true && $importRun->rows()->exists()) {
            return $this->processPersistedImportChunk($importRun, $import, $chunkSize, $runStartedAt);
        }

        if ($importRun->shouldProcessFailedRows()) {
            return $this->processFailedRowsChunk($importRun, $import, $chunkSize, $runStartedAt);
        }

        $path = Storage::disk('local')->path((string) $importRun->stored_path);
        $chunk = $import->fileRowsChunk(
            $path,
            (int) $importRun->processed_rows,
            $chunkSize,
            $importRun->sheet_index,
            $importRun->sheet_name,
            $importRun->mapping ?? []
        );

        if ($chunk['rows'] === []) {
            $importRun->update([
                'total_rows' => (int) $importRun->processed_rows,
                'heartbeat_at' => now(),
            ]);

            return [
                'processed_rows' => 0,
                'has_more' => false,
            ];
        }

        if ($isValidationRun) {
            $import->forMode(ImportRun::MODE_VALIDATE_ONLY);
            $duplicateKeys = $this->duplicateKeysForValidationRun($importRun, $import, $path);
            $import->validateRowsWithDuplicateKeys($chunk['rows'], $duplicateKeys);
            $this->rowService->persistValidationRowsChunk($importRun, $import->validationRows);
            $import->validationRows = [];
        } else {
            $import->execute($chunk['rows']);
        }

        $processedRows = count($chunk['rows']);
        $newProcessedRows = (int) $importRun->processed_rows + $processedRows;
        $totalRows = max((int) $chunk['total_rows'], $newProcessedRows);
        $hasMore = $newProcessedRows < $totalRows;

        $this->updateProgress($importRun, $import, $newProcessedRows, $totalRows, $runStartedAt);

        return [
            'processed_rows' => $processedRows,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return array{processed_rows: int, has_more: bool}
     */
    private function processPersistedImportChunk(ImportRun $importRun, ProductImport $import, int $chunkSize, float $runStartedAt): array
    {
        $rows = $this->rowService->claimPendingImportableRows($importRun, $chunkSize);
        if ($rows === []) {
            return [
                'processed_rows' => 0,
                'has_more' => $this->rowService->hasUnfinishedImportableRows($importRun),
            ];
        }

        $rowNumbers = collect($rows)->pluck('__row')->map(fn ($row): int => (int) $row)->all();
        $import->execute($rows);
        $this->rowService->synchronizeExecutionResultsForRows($importRun, $import, $rowNumbers);

        $processedRows = count($rows);
        $newProcessedRows = $this->rowService->executedImportableRowsCount($importRun);
        $totalRows = max((int) $importRun->total_rows, $importRun->rows()->where('status', 'valid')->count());
        $hasMore = $this->rowService->hasUnfinishedImportableRows($importRun->refresh());

        $this->updateProgress($importRun, $import, $newProcessedRows, $totalRows, $runStartedAt);

        return [
            'processed_rows' => $processedRows,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return array{processed_rows: int, has_more: bool}
     */
    private function processFailedRowsChunk(ImportRun $importRun, ProductImport $import, int $chunkSize, float $runStartedAt): array
    {
        $failedRows = collect($importRun->failed_rows ?? [])
            ->map(fn (array $row): array => $row['data'] ?? $row)
            ->values()
            ->all();
        $offset = (int) $importRun->processed_rows;
        $rows = array_slice($failedRows, $offset, $chunkSize);

        if ($rows === []) {
            return [
                'processed_rows' => 0,
                'has_more' => false,
            ];
        }

        $import->execute($rows);

        $processedRows = count($rows);
        $newProcessedRows = $offset + $processedRows;
        $totalRows = count($failedRows);
        $hasMore = $newProcessedRows < $totalRows;

        $this->updateProgress($importRun, $import, $newProcessedRows, $totalRows, $runStartedAt);

        return [
            'processed_rows' => $processedRows,
            'has_more' => $hasMore,
        ];
    }

    private function newImport(ImportRun $importRun): ProductImport
    {
        $import = (new ProductImport($this->productService))
            ->forMode($importRun->mode ?? ImportRun::MODE_CREATE_UPDATE)
            ->forImportRun($importRun->id);

        $import->processedCount = (int) $importRun->processed_rows;
        $import->successCount = (int) $importRun->success_count;
        $import->errorCount = (int) $importRun->error_count;
        $import->results = $importRun->results ?? [];
        $import->errors = $importRun->errors ?? [];

        return $import;
    }

    private function duplicateKeysForValidationRun(ImportRun $importRun, ProductImport $import, string $path): array
    {
        return $import->duplicateRowKeysForFileSelection(
            $path,
            $importRun->sheet_index,
            $importRun->sheet_name,
            $importRun->mapping ?? []
        );
    }

    private function updateProgress(ImportRun $importRun, ProductImport $import, int $processedRows, int $totalRows, float $runStartedAt): void
    {
        $summary = array_merge(
            $importRun->summary ?? [],
            $import->referenceSummary(),
            $this->validationRowsSummary($importRun),
            [
                'chunk_processing' => true,
                'chunk_size' => self::CHUNK_SIZE,
                'latest_results_limited_to' => self::RESULT_LIMIT,
                'latest_errors_limited_to' => self::RESULT_LIMIT,
                'source_file_deleted' => false,
                'metrics' => $this->metricsSummary($import, $processedRows, $runStartedAt),
            ],
            $importRun->rows()->exists()
                ? array_merge($this->rowService->summaryCounts($importRun), $this->rowService->groupingSummary($importRun))
                : []
        );

        $importRun->update([
            'status' => ImportRun::STATUS_RUNNING,
            'phase' => $this->isValidationRun($importRun) ? ImportRun::PHASE_VALIDATING : ImportRun::PHASE_IMPORTING,
            'total_rows' => $totalRows,
            'processed_rows' => $processedRows,
            'success_count' => $import->successCount,
            'error_count' => $import->errorCount,
            'results' => array_slice($import->results, -self::RESULT_LIMIT),
            'errors' => array_slice($import->errors, -self::RESULT_LIMIT),
            'failed_rows' => array_values(array_filter($import->errors, fn (array $error): bool => ! empty($error['data']))),
            'summary' => $summary,
            'heartbeat_at' => now(),
        ]);
    }

    private function markCompleted(ImportRun $importRun, ProductImport $import, float $runStartedAt): void
    {
        $summary = array_merge(
            $importRun->summary ?? [],
            $import->referenceSummary(),
            $this->validationRowsSummary($importRun),
            [
                'chunk_processing' => true,
                'source_file_deleted' => false,
                'metrics' => $this->metricsSummary($import, (int) $importRun->processed_rows, $runStartedAt),
            ],
            $importRun->rows()->exists()
                ? array_merge($this->rowService->summaryCounts($importRun), $this->rowService->groupingSummary($importRun))
                : []
        );

        $importRun->update([
            'status' => ImportRun::STATUS_COMPLETED,
            'phase' => ImportRun::PHASE_FINALIZING,
            'processed_rows' => (int) $importRun->processed_rows,
            'total_rows' => (int) $importRun->processed_rows,
            'success_count' => $import->successCount,
            'error_count' => $import->errorCount,
            'results' => array_slice($import->results, -self::RESULT_LIMIT),
            'errors' => array_slice($import->errors, -self::RESULT_LIMIT),
            'failed_rows' => array_values(array_filter($import->errors, fn (array $error): bool => ! empty($error['data']))),
            'summary' => $summary,
            'finished_at' => now(),
            'heartbeat_at' => now(),
        ]);
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
            'summary' => array_merge($importRun->summary ?? [], [
                'chunk_processing' => true,
                'source_file_deleted' => false,
            ]),
            'finished_at' => now(),
            'heartbeat_at' => now(),
        ]);
    }

    private function markFailed(ImportRun $importRun, string $message, ?ProductImport $import = null): void
    {
        $failureError = [
            'row' => 0,
            'label' => $importRun->original_filename,
            'error' => $message,
        ];

        $errors = $import === null || $import->errors === []
            ? [$failureError]
            : array_slice(array_merge($import->errors, [$failureError]), -self::RESULT_LIMIT);

        $importRun->update([
            'status' => ImportRun::STATUS_FAILED,
            'phase' => ImportRun::PHASE_FINALIZING,
            'processed_rows' => $import?->processedCount ?? $importRun->processed_rows,
            'success_count' => $import?->successCount ?? $importRun->success_count,
            'error_count' => $import?->errorCount ?? $importRun->error_count,
            'results' => $import === null ? ($importRun->results ?? []) : array_slice($import->results, -self::RESULT_LIMIT),
            'errors' => $errors,
            'failed_rows' => $import === null ? ($importRun->failed_rows ?? []) : array_values(array_filter($import->errors, fn (array $error): bool => ! empty($error['data']))),
            'summary' => array_merge($importRun->summary ?? [], [
                'chunk_processing' => true,
                'source_file_deleted' => false,
                'failure_message' => $message,
            ]),
            'finished_at' => now(),
            'heartbeat_at' => now(),
        ]);
    }

    private function validationRowsSummary(ImportRun $importRun): array
    {
        if (! $this->isValidationRun($importRun)) {
            return [];
        }

        $rowsRevision = (int) ($importRun->summary['rows_revision'] ?? 0);

        return [
            'validation_rows_count' => $importRun->rows()->count(),
            'validation_rows_limited' => true,
            'rows_revision' => $rowsRevision,
            'validated_rows_revision' => $rowsRevision,
            'rows_dirty' => false,
            'full_revalidation_status' => 'completed',
        ];
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

    private function isValidationRun(ImportRun $importRun): bool
    {
        return $importRun->mode === ImportRun::MODE_VALIDATE_ONLY
            || $importRun->run_type === ImportRun::TYPE_VALIDATION;
    }

    private function sourceFileExists(ImportRun $importRun): bool
    {
        return is_string($importRun->stored_path)
            && $importRun->stored_path !== ''
            && Storage::disk('local')->exists($importRun->stored_path);
    }
}
