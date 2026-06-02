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

    private const IMPORT_CHUNK_SIZE = 100;

    public int $timeout = 1800;

    public function __construct(public int $importRunId) {}

    public function handle(ProductService $productService, ?ProductImportRunRowService $rowService = null): void
    {
        $rowService ??= app(ProductImportRunRowService::class);

        $started = ImportRun::query()
            ->whereKey($this->importRunId)
            ->where('status', ImportRun::STATUS_PENDING)
            ->update([
                'status' => ImportRun::STATUS_RUNNING,
                'started_at' => now(),
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
            $import = (new ProductImport($productService))
                ->forMode($importRun->mode ?? ImportRun::MODE_CREATE_UPDATE)
                ->forImportRun($importRun->id);

            $afterChunk = function (ProductImport $chunkImport, int $processedRows, int $totalRows) use ($importRun): bool {
                $importRun->refresh();
                if ($importRun->status === ImportRun::STATUS_CANCEL_REQUESTED) {
                    $this->markCancelled($importRun, $processedRows);

                    return false;
                }

                $importRun->update([
                    'total_rows' => $totalRows,
                    'processed_rows' => $processedRows,
                    'success_count' => $chunkImport->successCount,
                    'error_count' => $chunkImport->errorCount,
                    'results' => array_slice($chunkImport->results, -100),
                    'errors' => array_slice($chunkImport->errors, -100),
                    'summary' => $this->summaryForImportRun($importRun, $chunkImport, [
                        'latest_results_limited_to' => 100,
                        'latest_errors_limited_to' => 100,
                    ]),
                ]);

                return true;
            };

            if (($importRun->summary['use_persisted_rows_snapshot'] ?? false) === true && $importRun->rows()->exists()) {
                $totalRows = $importRun->rows()->where('status', 'valid')->count();
                $importRun->update([
                    'total_rows' => $totalRows,
                    'processed_rows' => 0,
                ]);
                $shouldContinue = true;
                $rowService->eachImportableRowChunk($importRun, self::IMPORT_CHUNK_SIZE, function (array $chunk) use ($import, $afterChunk, $totalRows, &$shouldContinue): void {
                    if (! $shouldContinue) {
                        return;
                    }

                    $import->execute($chunk);
                    if (! $afterChunk($import, $import->processedCount, $totalRows)) {
                        $shouldContinue = false;
                    }
                });
                $rowService->synchronizeExecutionErrors($importRun, $import);
            } elseif ($importRun->shouldProcessFailedRows()) {
                $rows = collect($importRun->failed_rows)->map(fn (array $row): array => $row['data'] ?? $row)->values()->all();
                $importRun->update([
                    'total_rows' => count($rows),
                    'processed_rows' => 0,
                ]);
                $import->execute($rows);
            } else {
                $path = Storage::disk('local')->path($importRun->stored_path);
                $selectedSheet = $import->resolveSelectedSheet($path, $importRun->sheet_index, $importRun->sheet_name, $importRun->mapping ?? []);
                $importRun->update([
                    'total_rows' => (int) ($selectedSheet['row_count'] ?? 0),
                    'processed_rows' => 0,
                ]);

                if ($importRun->mode === ImportRun::MODE_VALIDATE_ONLY || $importRun->run_type === ImportRun::TYPE_VALIDATION) {
                    $import->validateFileInChunks($path, self::IMPORT_CHUNK_SIZE, $afterChunk, $importRun->sheet_index, $importRun->sheet_name, $importRun->mapping ?? []);
                } else {
                    $import->importInChunks($path, self::IMPORT_CHUNK_SIZE, $afterChunk, $importRun->sheet_index, $importRun->sheet_name, $importRun->mapping ?? []);
                }
            }

            $importRun->refresh();
            if ($importRun->status === ImportRun::STATUS_CANCELLED) {
                return;
            }

            $this->persistValidationRows($importRun, $import, $rowService);

            $importRun->update([
                'status' => ImportRun::STATUS_COMPLETED,
                'total_rows' => $import->processedCount,
                'processed_rows' => $import->processedCount,
                'success_count' => $import->successCount,
                'error_count' => $import->errorCount,
                'results' => array_slice($import->results, -100),
                'errors' => array_slice($import->errors, -100),
                'failed_rows' => array_values(array_filter($import->errors, fn (array $error): bool => ! empty($error['data']))),
                'summary' => $this->summaryForImportRun($importRun, $import, array_merge([
                    'latest_results_limited_to' => 100,
                    'latest_errors_limited_to' => 100,
                    'records' => $importRun->records()->selectRaw('action, count(*) as total')->groupBy('action')->pluck('total', 'action')->all(),
                    'source_file_deleted' => false,
                ], $importRun->rows()->exists()
                    ? array_merge($rowService->summaryCounts($importRun), $rowService->groupingSummary($importRun))
                    : [])),
                'finished_at' => now(),
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

    private function markCancelled(ImportRun $importRun, int $processedRows): void
    {
        $importRun->update([
            'status' => ImportRun::STATUS_CANCELLED,
            'processed_rows' => $processedRows,
            'summary' => array_merge($importRun->summary ?? [], [
                'source_file_deleted' => false,
            ]),
            'finished_at' => now(),
        ]);
        $importRun->refresh();
        $this->recordActivity($importRun, 'cancelled', 'Importacion cancelada durante el procesamiento.');
    }

    private function markFailed(ImportRun $importRun, string $message, ?ProductImport $import = null): void
    {
        $importRun->refresh();
        if ($import !== null) {
            app(ProductImportRunRowService::class)->persistValidationRows($importRun, $import);
        }

        $importRun->update([
            'status' => ImportRun::STATUS_FAILED,
            'processed_rows' => $import?->processedCount ?? $importRun->processed_rows,
            'success_count' => $import?->successCount ?? $importRun->success_count,
            'error_count' => $import?->errorCount ?? $importRun->error_count,
            'results' => $import === null ? ($importRun->results ?? []) : array_slice($import->results, -100),
            'errors' => $import === null || empty($import->errors)
                ? [[
                    'row' => 0,
                    'label' => $importRun->original_filename,
                    'error' => $message,
                ]]
                : array_slice($import->errors, -100),
            'failed_rows' => $import === null ? ($importRun->failed_rows ?? []) : array_values(array_filter($import->errors, fn (array $error): bool => ! empty($error['data']))),
            'summary' => array_merge(
                $importRun->summary ?? [],
                $import === null ? [] : $this->validationRowsSummary($importRun, $import),
                [
                    'source_file_deleted' => false,
                    'failure_message' => $message,
                ]
            ),
            'finished_at' => now(),
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

        return [
            'validation_rows_count' => count($import->validationRows),
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

        $rowService->persistValidationRows($importRun, $import);
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
