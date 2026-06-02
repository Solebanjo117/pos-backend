<?php

namespace App\Jobs;

use App\Imports\ProductImport;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\ProductImportRunRowService;
use App\Services\ProductService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RevalidateProductImportRunRows implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(public int $importRunId, public ?int $causerId = null) {}

    public function handle(ProductService $productService, ProductImportRunRowService $rowService): void
    {
        $importRun = ImportRun::query()->find($this->importRunId);
        if (! $importRun || ($importRun->summary['full_revalidation_status'] ?? null) !== 'pending') {
            return;
        }

        $summary = $importRun->summary ?? [];
        $totalRows = $importRun->rows()->count();
        $importRun->update([
            'total_rows' => $totalRows,
            'processed_rows' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'summary' => array_merge($summary, [
                'full_revalidation_status' => 'running',
                'revalidation_progress' => [
                    'status' => 'running',
                    'processed_rows' => 0,
                    'total_rows' => $totalRows,
                    'rows_revision' => (int) ($summary['rows_revision'] ?? 0),
                    'validated_rows_revision' => (int) ($summary['validated_rows_revision'] ?? 0),
                ],
            ]),
        ]);

        try {
            $import = (new ProductImport($productService))->forMode(ImportRun::MODE_VALIDATE_ONLY);
            $rowService->revalidatePersistedRows($importRun, $import, 500, function (ProductImport $chunkImport) use ($importRun, $summary, $totalRows): void {
                $importRun->update([
                    'processed_rows' => $chunkImport->processedCount,
                    'success_count' => $chunkImport->successCount,
                    'error_count' => $chunkImport->errorCount,
                    'errors' => array_slice($chunkImport->errors, -100),
                    'failed_rows' => array_values(array_filter($chunkImport->errors, fn (array $error): bool => ! empty($error['data']))),
                    'summary' => array_merge($importRun->summary ?? $summary, [
                        'full_revalidation_status' => 'running',
                        'revalidation_progress' => [
                            'status' => 'running',
                            'processed_rows' => $chunkImport->processedCount,
                            'total_rows' => $totalRows,
                            'rows_revision' => (int) ($summary['rows_revision'] ?? 0),
                            'validated_rows_revision' => (int) ($summary['validated_rows_revision'] ?? 0),
                        ],
                    ]),
                ]);
            });

            $importRun->refresh();
            $counts = $rowService->summaryCounts($importRun);

            $importRun->update([
                'total_rows' => $totalRows,
                'processed_rows' => $import->processedCount,
                'success_count' => $import->successCount,
                'error_count' => $import->errorCount,
                'errors' => array_slice($import->errors, -100),
                'failed_rows' => array_values(array_filter($import->errors, fn (array $error): bool => ! empty($error['data']))),
                'summary' => array_merge(
                    $importRun->summary ?? $summary,
                    $import->referenceSummary(),
                    $counts,
                    $rowService->groupingSummary($importRun),
                    [
                        'validated_rows_revision' => (int) ($summary['rows_revision'] ?? 0),
                        'rows_dirty' => false,
                        'full_revalidation_status' => 'completed',
                        'revalidation_progress' => [
                            'status' => 'completed',
                            'processed_rows' => $import->processedCount,
                            'total_rows' => $totalRows,
                            'rows_revision' => (int) ($summary['rows_revision'] ?? 0),
                            'validated_rows_revision' => (int) ($summary['rows_revision'] ?? 0),
                        ],
                    ]
                ),
            ]);

            $this->recordActivity(
                $importRun,
                'rows_revalidated',
                'Filas de importacion revalidadas completamente.',
                [
                    'rows_revision' => (int) ($summary['rows_revision'] ?? 0),
                    'rows_count' => $totalRows,
                    'error_count' => $import->errorCount,
                ]
            );
        } catch (\Throwable $exception) {
            $importRun->update([
                'summary' => array_merge($summary, [
                    'full_revalidation_status' => 'failed',
                    'rows_dirty' => true,
                    'full_revalidation_error' => $exception->getMessage(),
                ]),
            ]);

            $this->recordActivity(
                $importRun,
                'rows_revalidation_failed',
                'Fallo la revalidacion completa de filas de importacion.',
                [
                    'message' => $exception->getMessage(),
                    'rows_revision' => (int) ($summary['rows_revision'] ?? 0),
                ]
            );
        }
    }

    private function recordActivity(ImportRun $importRun, string $event, string $description, array $properties = []): void
    {
        $summary = $importRun->summary ?? [];
        $causer = $this->causerId
            ? User::query()->find($this->causerId)
            : $importRun->user;

        activity('product_import')
            ->causedBy($causer)
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
                'rows_revision' => (int) ($summary['rows_revision'] ?? 0),
                'rows_count' => (int) $importRun->total_rows,
                'error_count' => (int) $importRun->error_count,
                'source' => 'rows_revalidation',
            ], $properties))
            ->log($description);
    }
}
