<?php

namespace App\Jobs;

use App\Imports\ProductImport;
use App\Models\ImportRun;
use App\Services\ProductService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ProcessProductImportRun implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(public int $importRunId) {}

    public function handle(ProductService $productService): void
    {
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

            if ($importRun->shouldProcessFailedRows()) {
                $rows = collect($importRun->failed_rows)->map(fn (array $row): array => $row['data'] ?? $row)->values()->all();
                $import->execute($rows);
            } else {
                $path = Storage::disk('local')->path($importRun->stored_path);
                if ($importRun->mode === ImportRun::MODE_VALIDATE_ONLY || $importRun->run_type === ImportRun::TYPE_VALIDATION) {
                    $import->validateFileInChunks($path, 500, $afterChunk, $importRun->sheet_index, $importRun->sheet_name, $importRun->mapping ?? []);
                } else {
                    $import->importInChunks($path, 500, $afterChunk, $importRun->sheet_index, $importRun->sheet_name, $importRun->mapping ?? []);
                }
            }

            $importRun->refresh();
            if ($importRun->status === ImportRun::STATUS_CANCELLED) {
                return;
            }

            $importRun->update([
                'status' => ImportRun::STATUS_COMPLETED,
                'total_rows' => $import->processedCount,
                'processed_rows' => $import->processedCount,
                'success_count' => $import->successCount,
                'error_count' => $import->errorCount,
                'results' => array_slice($import->results, -100),
                'errors' => array_slice($import->errors, -100),
                'failed_rows' => array_values(array_filter($import->errors, fn (array $error): bool => ! empty($error['data']))),
                'summary' => $this->summaryForImportRun($importRun, $import, [
                    'latest_results_limited_to' => 100,
                    'latest_errors_limited_to' => 100,
                    'records' => $importRun->records()->selectRaw('action, count(*) as total')->groupBy('action')->pluck('total', 'action')->all(),
                    'source_file_deleted' => false,
                ]),
                'finished_at' => now(),
            ]);
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
    }

    private function markFailed(ImportRun $importRun, string $message, ?ProductImport $import = null): void
    {
        $importRun->refresh();
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
            'validation_rows' => $import->validationRows,
            'validation_rows_count' => count($import->validationRows),
            'validation_rows_limited' => false,
        ];
    }
}
