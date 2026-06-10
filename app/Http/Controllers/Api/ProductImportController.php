<?php

namespace App\Http\Controllers\Api;

use App\Exports\ProductImportTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\BatchProductImportReferencesRequest;
use App\Http\Requests\BatchProductImportVehicleCandidatesRequest;
use App\Http\Requests\ExecuteImportRequest;
use App\Http\Requests\ExportProductImportPreviewRequest;
use App\Http\Requests\PreviewImportRequest;
use App\Http\Requests\StoreImportProfileRequest;
use App\Http\Requests\StoreProductImportRequest;
use App\Http\Requests\UpdateProductImportRunMappingRequest;
use App\Http\Requests\UpdateProductImportRunRowsRequest;
use App\Http\Requests\ValidateProductImportRowsRequest;
use App\Imports\ProductImport;
use App\Jobs\ProcessProductImportRun;
use App\Jobs\RevalidateProductImportRunRows;
use App\Models\ImportProfile;
use App\Models\ImportRun;
use App\Models\ImportRunRow;
use App\Models\User;
use App\Services\ProductImportReferenceBatchService;
use App\Services\ProductImportRunChunkProcessor;
use App\Services\ProductImportRunReconciler;
use App\Services\ProductImportRunRowService;
use App\Services\ProductImportWorkerSupervisor;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductImportController extends Controller
{
    private const ASYNC_PREVIEW_ROW_THRESHOLD = 200;

    private const MAX_ACTIVE_RUNS_PER_USER = 2;

    private const PROCESS_NOW_MAX_ROWS = 5000;

    private const ACTIVE_RUN_STATUSES = [
        ImportRun::STATUS_PENDING,
        ImportRun::STATUS_RUNNING,
        ImportRun::STATUS_CANCEL_REQUESTED,
    ];

    public function __construct(
        private readonly ProductService $productService,
        private readonly ProductImportRunReconciler $runReconciler,
        private readonly ProductImportRunRowService $rowService,
        private readonly ProductImportRunChunkProcessor $chunkProcessor,
        private readonly ProductImportWorkerSupervisor $workerSupervisor
    ) {}

    public function template(): BinaryFileResponse
    {
        return Excel::download(new ProductImportTemplate, 'plantilla_productos.xlsx');
    }

    public function preview(PreviewImportRequest $request): JsonResponse
    {
        if ($request->filled('import_run_id')) {
            $importRun = ImportRun::query()
                ->where('entity', 'products')
                ->visibleTo($request->user())
                ->findOrFail($request->integer('import_run_id'));

            if ($response = $this->validateRestartableValidationRun($importRun)) {
                return $response;
            }
        }

        $import = new ProductImport($this->productService);
        try {
            $metadata = $import->previewMetadata(
                $request->file('file'),
                $request->has('sheet_index') ? $request->integer('sheet_index') : null,
                $request->input('sheet_name'),
                $request->input('mapping', [])
            );

            $totalRows = (int) ($metadata['selected_sheet']['row_count'] ?? 0);
            if ($totalRows > self::ASYNC_PREVIEW_ROW_THRESHOLD) {
                return $this->startFileRun(
                    $request,
                    ImportRun::TYPE_VALIDATION,
                    ImportRun::MODE_VALIDATE_ONLY,
                    $metadata,
                    true
                );
            }

            $preview = $import->previewWithSummary(
                $request->file('file'),
                null,
                $request->has('sheet_index') ? $request->integer('sheet_index') : null,
                $request->input('sheet_name'),
                $request->input('mapping', [])
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $rows = $this->visibleValidationRows($preview['rows']);

        return response()->json([
            'success' => true,
            'data' => [
                'rows' => $rows,
                'count' => count($rows),
                'shown_count' => count($rows),
                'total_rows' => $preview['total_rows'],
                'max_rows' => $preview['max_rows'],
                'preview_limited' => $preview['preview_limited'],
                'summary' => $preview['summary'],
                'sheets' => $preview['sheets'],
                'selected_sheet' => $preview['selected_sheet'],
                'recommended_sheet' => $preview['recommended_sheet'],
                'detected_columns' => $preview['detected_columns'],
                'suggested_mapping' => $preview['suggested_mapping'],
                'required_fields' => $preview['required_fields'],
                'sheet_detection_warning' => $preview['sheet_detection_warning'],
            ],
        ]);
    }

    public function execute(ExecuteImportRequest $request): JsonResponse
    {
        $import = (new ProductImport($this->productService))
            ->forMode($request->input('mode', ImportRun::MODE_CREATE_UPDATE));
        $import->execute($request->input('rows', []));

        return response()->json([
            'success' => true,
            'data' => [
                'success_count' => $import->successCount,
                'error_count' => $import->errorCount,
                'results' => $import->results,
                'errors' => $import->errors,
            ],
            'message' => "Importados: {$import->successCount}, Errores: {$import->errorCount}",
        ]);
    }

    public function validateRows(ValidateProductImportRowsRequest $request): JsonResponse
    {
        $import = (new ProductImport($this->productService))
            ->forMode($request->input('mode', ImportRun::MODE_VALIDATE_ONLY));

        $import->validateRows($request->input('rows', []));
        $summary = $import->referenceSummary();

        return response()->json([
            'success' => true,
            'data' => [
                'rows' => $this->visibleValidationRows($import->validationRows),
                'count' => count($import->validationRows),
                'success_count' => $import->successCount,
                'error_count' => $import->errorCount,
                'summary' => $summary,
            ],
        ]);
    }

    public function exportPreview(ExportProductImportPreviewRequest $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Preview editado');

        $columns = collect((new ProductImport($this->productService))->getColumns())
            ->pluck('key')
            ->values()
            ->all();
        $headers = array_merge(['Fila', 'Estado', 'Accion prevista', 'Errores'], $columns);

        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }

        foreach ($request->validated('rows') as $rowIndex => $row) {
            $sheetRow = $rowIndex + 2;
            $errors = $row['__errors'] ?? [];

            $sheet->setCellValue([1, $sheetRow], $row['__row'] ?? '');
            $sheet->setCellValue([2, $sheetRow], ($row['__valid'] ?? false) ? 'Valida' : 'Con errores');
            $sheet->setCellValue([3, $sheetRow], $row['__action_type'] ?? '');
            $sheet->setCellValue([4, $sheetRow], is_array($errors) ? implode(' | ', $errors) : (string) $errors);

            foreach ($columns as $columnIndex => $column) {
                $value = $row[$column] ?? '';
                $sheet->setCellValue([$columnIndex + 5, $sheetRow], is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value);
            }
        }

        foreach (range(1, count($headers)) as $columnIndex) {
            $sheet->getColumnDimensionByColumn($columnIndex)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'preview-productos-editado.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function batchReferences(BatchProductImportReferencesRequest $request, ProductImportReferenceBatchService $batchService): JsonResponse
    {
        $data = $batchService->createReferences(
            $request->validated('categories') ?? [],
            $request->validated('brands') ?? []
        );

        activity('product_import')
            ->causedBy($request->user())
            ->event('references_batch_created')
            ->withProperties([
                'entity' => 'products',
                'source' => 'references_batch',
                'categories_count' => count($request->validated('categories') ?? []),
                'brands_count' => count($request->validated('brands') ?? []),
                'created_count' => (int) ($data['summary']['created_count'] ?? 0),
                'error_count' => (int) ($data['summary']['error_count'] ?? 0),
            ])
            ->log('Referencias de importacion creadas en lote.');

        return response()->json([
            'success' => true,
            'data' => $data,
        ], $data['summary']['error_count'] > 0 ? 207 : 201);
    }

    public function batchVehicleCandidates(BatchProductImportVehicleCandidatesRequest $request, ProductImportReferenceBatchService $batchService): JsonResponse
    {
        $data = $batchService->createVehicleCandidates($request->validated('candidates'));

        activity('product_import')
            ->causedBy($request->user())
            ->event('vehicle_candidates_batch_created')
            ->withProperties([
                'entity' => 'products',
                'source' => 'vehicle_candidates_batch',
                'candidates_count' => count($request->validated('candidates')),
                'created_count' => (int) ($data['summary']['created_count'] ?? 0),
                'error_count' => (int) ($data['summary']['error_count'] ?? 0),
            ])
            ->log('Candidatos vehiculares de importacion creados en lote.');

        return response()->json([
            'success' => true,
            'data' => $data,
        ], $data['summary']['error_count'] > 0 ? 207 : ($data['summary']['created_count'] > 0 ? 201 : 200));
    }

    public function import(StoreProductImportRequest $request): JsonResponse
    {
        if ($request->filled('validated_import_run_id')) {
            return $this->startImportFromValidatedRun($request);
        }

        if (! $request->boolean('force_without_preview')) {
            return response()->json([
                'success' => false,
                'message' => 'Primero valide el archivo completo antes de importarlo.',
            ], 422);
        }

        if ($request->input('force_confirmation') !== 'IMPORTAR SIN PREVIEW') {
            return response()->json([
                'success' => false,
                'message' => 'Debe confirmar con IMPORTAR SIN PREVIEW para importar sin validacion previa.',
            ], 422);
        }

        if (! $request->hasFile('file')) {
            return response()->json([
                'success' => false,
                'message' => 'Debe seleccionar un archivo para importar sin preview.',
            ], 422);
        }

        return $this->startFileRun(
            $request,
            ImportRun::TYPE_IMPORT,
            $request->input('mode', ImportRun::MODE_CREATE_UPDATE),
            null,
            false,
            [
                'force_without_preview' => true,
                'force_reason' => $request->input('force_reason'),
            ]
        );
    }

    public function validateFile(PreviewImportRequest $request): JsonResponse
    {
        return $this->startFileRun($request, ImportRun::TYPE_VALIDATION, ImportRun::MODE_VALIDATE_ONLY);
    }

    private function startFileRun(
        PreviewImportRequest|StoreProductImportRequest $request,
        string $runType,
        string $mode,
        ?array $metadata = null,
        bool $asyncPreview = false,
        array $summaryOverrides = []
    ): JsonResponse {
        $restartImportRunId = $runType === ImportRun::TYPE_VALIDATION && $request->filled('import_run_id')
            ? $request->integer('import_run_id')
            : null;

        if ($response = $this->activeRunLimitResponse($request, $restartImportRunId)) {
            return $response;
        }

        $file = $request->file('file');
        $sheetIndex = $request->has('sheet_index') ? $request->integer('sheet_index') : null;
        $sheetName = $request->input('sheet_name');
        $mapping = $request->input('mapping', []);
        $profile = null;

        if ($request->filled('profile_id')) {
            $profile = ImportProfile::query()
                ->where('entity', 'products')
                ->visibleTo($request->user())
                ->find($request->integer('profile_id'));

            if (! $profile) {
                return response()->json([
                    'success' => false,
                    'message' => 'El perfil de importacion seleccionado no esta disponible.',
                ], 422);
            }
        }

        if ($profile) {
            $mapping = ! empty($mapping) ? $mapping : ($profile->mapping ?? []);
            $sheetIndex ??= $profile->sheet_index;
            $sheetName = $sheetName ?: $profile->sheet_name;
            $mode = $request->input('mode', $profile->mode ?? $mode);
        }

        if ($metadata === null) {
            try {
                $metadata = (new ProductImport($this->productService))->previewMetadata(
                    $file,
                    $sheetIndex,
                    $sheetName,
                    $mapping
                );
            } catch (\RuntimeException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        }

        if ($sheetName === null || trim((string) $sheetName) === '') {
            try {
                $selectedSheet = $metadata['selected_sheet']
                    ?? (new ProductImport($this->productService))->resolveSelectedSheet($file, $sheetIndex, null, $mapping);
                $sheetIndex = $selectedSheet['index'];
                $sheetName = $selectedSheet['name'];
            } catch (\RuntimeException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        }

        if ($runType === ImportRun::TYPE_VALIDATION && $request->filled('import_run_id')) {
            return $this->restartValidationRun(
                $request,
                $mapping,
                $sheetIndex,
                $sheetName,
                $metadata ?? [],
                $asyncPreview
            );
        }

        $storedPath = $file->store('imports');

        if (! is_string($storedPath)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo guardar el archivo para importarlo.',
            ], 500);
        }

        $metadataSummary = [
            'sheets' => $metadata['sheets'] ?? [],
            'selected_sheet' => $metadata['selected_sheet'] ?? null,
            'recommended_sheet' => $metadata['recommended_sheet'] ?? null,
            'detected_columns' => $metadata['detected_columns'] ?? [],
            'suggested_mapping' => $metadata['suggested_mapping'] ?? [],
            'required_fields' => $metadata['required_fields'] ?? [],
            'sheet_detection_warning' => $metadata['sheet_detection_warning'] ?? null,
        ];

        $importRun = ImportRun::query()->create([
            'user_id' => $request->user()?->id,
            'entity' => 'products',
            'import_profile_id' => $profile?->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'sheet_index' => $sheetIndex,
            'sheet_name' => $sheetName,
            'run_type' => $runType,
            'mode' => $mode,
            'total_rows' => (int) ($metadata['selected_sheet']['row_count'] ?? 0),
            'mapping' => $mapping,
            'status' => ImportRun::STATUS_PENDING,
            'phase' => ImportRun::PHASE_QUEUED,
            'results' => [],
            'errors' => [],
            'summary' => array_merge($metadataSummary, $asyncPreview ? [
                'preview_async' => true,
            ] : [], $summaryOverrides),
        ]);

        if (($summaryOverrides['force_without_preview'] ?? false) === true) {
            $this->recordImportRunActivity(
                $importRun,
                $request,
                'force_without_preview',
                'Importacion iniciada sin preview validado.'
            );
        }

        $this->recordImportRunActivity(
            $importRun,
            $request,
            $runType === ImportRun::TYPE_VALIDATION ? 'validation_started' : 'import_started',
            $runType === ImportRun::TYPE_VALIDATION
                ? 'Validacion de importacion iniciada.'
                : 'Importacion de productos iniciada.'
        );

        return response()->json([
            'success' => true,
            'data' => [
                'import_run_id' => $importRun->id,
                'status' => $importRun->status,
                'mode' => $importRun->mode,
                'run_type' => $importRun->run_type,
                'async' => $asyncPreview,
                'chunk_processing' => true,
                'process_chunk_url' => "/api/v1/products/import/runs/{$importRun->id}/process-chunk",
                'total_rows' => $importRun->total_rows,
                'preview_limited' => false,
                'sheets' => $metadata['sheets'] ?? [],
                'selected_sheet' => $metadata['selected_sheet'] ?? null,
                'recommended_sheet' => $metadata['recommended_sheet'] ?? null,
                'detected_columns' => $metadata['detected_columns'] ?? [],
                'suggested_mapping' => $metadata['suggested_mapping'] ?? [],
                'required_fields' => $metadata['required_fields'] ?? [],
                'sheet_detection_warning' => $metadata['sheet_detection_warning'] ?? null,
            ],
            'message' => $this->startFileRunMessage($runType, $asyncPreview),
        ]);
    }

    private function startFileRunMessage(string $runType, bool $asyncPreview): string
    {
        if ($asyncPreview) {
            return 'Previsualizacion lista para procesar por lotes desde esta pantalla.';
        }

        if ($runType === ImportRun::TYPE_VALIDATION) {
            return 'Validacion lista para procesar por lotes desde esta pantalla.';
        }

        return 'Importacion lista para procesar por lotes desde esta pantalla.';
    }

    private function startImportFromValidatedRun(StoreProductImportRequest $request): JsonResponse
    {
        if ($response = $this->activeRunLimitResponse($request)) {
            return $response;
        }

        $validationRun = ImportRun::query()
            ->where('entity', 'products')
            ->visibleTo($request->user())
            ->findOrFail($request->integer('validated_import_run_id'));

        if (
            $validationRun->run_type !== ImportRun::TYPE_VALIDATION
            || $validationRun->mode !== ImportRun::MODE_VALIDATE_ONLY
        ) {
            return response()->json([
                'success' => false,
                'message' => 'El proceso seleccionado no es una validacion de productos.',
            ], 422);
        }

        if ($validationRun->status !== ImportRun::STATUS_COMPLETED) {
            return response()->json([
                'success' => false,
                'message' => 'La validacion seleccionada aun no esta completada.',
            ], 409);
        }

        if ((int) ($validationRun->summary['validation_rows_count'] ?? 0) <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'La validacion seleccionada no tiene filas validadas.',
            ], 422);
        }

        $summary = $validationRun->summary ?? [];
        if (
            ($summary['rows_dirty'] ?? false) === true
            || (int) ($summary['rows_revision'] ?? 0) !== (int) ($summary['validated_rows_revision'] ?? 0)
            || (($summary['full_revalidation_status'] ?? 'completed') !== 'completed')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'La validacion seleccionada tiene cambios pendientes de revalidacion completa.',
            ], 422);
        }

        $validRowsCount = $validationRun->rows()->exists()
            ? $validationRun->rows()->where('status', 'valid')->count()
            : max(0, (int) ($summary['validation_rows_count'] ?? 0) - (int) $validationRun->error_count);

        if ($validRowsCount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'La validacion seleccionada no tiene filas validas para importar.',
            ], 422);
        }

        if (! $this->sourceFileExists($validationRun)) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontro el archivo validado para importar.',
            ], 422);
        }

        $storedPath = $this->copyValidatedImportSource($validationRun);
        if ($storedPath === null) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo preparar una copia del archivo validado para importar.',
            ], 500);
        }

        $importRun = DB::transaction(function () use ($request, $validationRun, $storedPath, $summary): ImportRun {
            $importRun = ImportRun::query()->create([
                'user_id' => $request->user()?->id,
                'entity' => 'products',
                'import_profile_id' => $validationRun->import_profile_id,
                'parent_import_run_id' => $validationRun->id,
                'original_filename' => $validationRun->original_filename,
                'stored_path' => $storedPath,
                'sheet_index' => $validationRun->sheet_index,
                'sheet_name' => $validationRun->sheet_name,
                'run_type' => ImportRun::TYPE_IMPORT,
                'mode' => $request->input('mode', ImportRun::MODE_CREATE_UPDATE),
                'total_rows' => $validationRun->total_rows,
                'mapping' => $validationRun->mapping ?? [],
                'status' => ImportRun::STATUS_PENDING,
                'phase' => ImportRun::PHASE_QUEUED,
                'results' => [],
                'errors' => [],
                'summary' => [
                    'validated_import_run_id' => $validationRun->id,
                    'source_validation_completed_at' => $validationRun->finished_at,
                    'source_validation_rows_count' => (int) ($validationRun->summary['validation_rows_count'] ?? 0),
                    'source_validation_error_count' => $validationRun->error_count,
                    'source_validation_conflict_count' => (int) ($summary['conflict_count'] ?? 0),
                    'source_rows_revision' => (int) ($summary['rows_revision'] ?? 0),
                    'use_persisted_rows_snapshot' => $validationRun->rows()->exists(),
                ],
            ]);

            if ($validationRun->rows()->exists()) {
                $this->rowService->createSnapshot($validationRun, $importRun);
            }

            activity('product_import')
                ->causedBy($request->user())
                ->performedOn($importRun)
                ->event('import_snapshot_created')
                ->withProperties($this->importRunActivityProperties($importRun, [
                    'validated_import_run_id' => $validationRun->id,
                    'source_rows_revision' => (int) ($summary['rows_revision'] ?? 0),
                ]))
                ->log('Snapshot de filas persistidas preparado para importacion.');

            return $importRun;
        });

        activity('product_import')
            ->causedBy($request->user())
            ->performedOn($importRun)
            ->event('validated_import_started')
            ->withProperties($this->importRunActivityProperties($importRun, [
                'validated_import_run_id' => $validationRun->id,
            ]))
            ->log('Importacion iniciada desde validacion completada.');

        return response()->json([
            'success' => true,
            'data' => [
                'import_run_id' => $importRun->id,
                'status' => $importRun->status,
                'mode' => $importRun->mode,
                'run_type' => $importRun->run_type,
                'async' => false,
                'chunk_processing' => true,
                'process_chunk_url' => "/api/v1/products/import/runs/{$importRun->id}/process-chunk",
                'total_rows' => $importRun->total_rows,
                'preview_limited' => false,
            ],
            'message' => 'Importacion lista para procesar por lotes desde una validacion completada.',
        ]);
    }

    private function restartValidationRun(PreviewImportRequest $request, array $mapping, ?int $sheetIndex, ?string $sheetName, array $metadata, bool $asyncPreview): JsonResponse
    {
        if ($response = $this->activeRunLimitResponse($request, $request->integer('import_run_id'))) {
            return $response;
        }

        $importRun = ImportRun::query()
            ->where('entity', 'products')
            ->visibleTo($request->user())
            ->findOrFail($request->integer('import_run_id'));

        if ($response = $this->validateRestartableValidationRun($importRun)) {
            return $response;
        }

        $file = $request->file('file');
        $storedPath = $file->store('imports');

        if (! is_string($storedPath)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo guardar el archivo para importarlo.',
            ], 500);
        }

        $previousStoredPath = $importRun->stored_path;

        DB::transaction(function () use ($importRun, $request, $mapping, $sheetIndex, $sheetName, $metadata, $storedPath, $file, $asyncPreview): void {
            $importRun->records()->delete();
            $importRun->rows()->delete();
            $importRun->update([
                'user_id' => $request->user()?->id,
                'import_profile_id' => $request->filled('profile_id') ? $request->integer('profile_id') : null,
                'parent_import_run_id' => null,
                'original_filename' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'sheet_index' => $sheetIndex,
                'sheet_name' => $sheetName,
                'run_type' => ImportRun::TYPE_VALIDATION,
                'mode' => ImportRun::MODE_VALIDATE_ONLY,
                'total_rows' => (int) ($metadata['selected_sheet']['row_count'] ?? 0),
                'processed_rows' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'mapping' => $mapping,
                'failed_rows' => [],
                'status' => ImportRun::STATUS_PENDING,
                'phase' => ImportRun::PHASE_QUEUED,
                'results' => [],
                'errors' => [],
                'summary' => array_merge($asyncPreview ? [
                    'preview_async' => true,
                ] : [], [
                    'sheets' => $metadata['sheets'] ?? [],
                    'selected_sheet' => $metadata['selected_sheet'] ?? null,
                    'recommended_sheet' => $metadata['recommended_sheet'] ?? null,
                    'detected_columns' => $metadata['detected_columns'] ?? [],
                    'suggested_mapping' => $metadata['suggested_mapping'] ?? [],
                    'required_fields' => $metadata['required_fields'] ?? [],
                    'sheet_detection_warning' => $metadata['sheet_detection_warning'] ?? null,
                ]),
                'started_at' => null,
                'finished_at' => null,
            ]);
        });

        if (
            is_string($previousStoredPath)
            && $previousStoredPath !== ''
            && $previousStoredPath !== $storedPath
            && ! $this->storedPathIsReferenced($previousStoredPath)
        ) {
            Storage::disk('local')->delete($previousStoredPath);
        }

        $this->recordImportRunActivity($importRun, $request, 'validation_restarted', 'Validacion persistida reiniciada.');

        return response()->json([
            'success' => true,
            'data' => [
                'import_run_id' => $importRun->id,
                'status' => $importRun->status,
                'mode' => $importRun->mode,
                'run_type' => $importRun->run_type,
                'async' => $asyncPreview,
                'chunk_processing' => true,
                'process_chunk_url' => "/api/v1/products/import/runs/{$importRun->id}/process-chunk",
                'total_rows' => $importRun->total_rows,
                'preview_limited' => false,
                'sheets' => $metadata['sheets'] ?? [],
                'selected_sheet' => $metadata['selected_sheet'] ?? null,
                'recommended_sheet' => $metadata['recommended_sheet'] ?? null,
                'detected_columns' => $metadata['detected_columns'] ?? [],
                'suggested_mapping' => $metadata['suggested_mapping'] ?? [],
                'required_fields' => $metadata['required_fields'] ?? [],
                'sheet_detection_warning' => $metadata['sheet_detection_warning'] ?? null,
            ],
            'message' => $asyncPreview
                ? 'Previsualizacion actualizada y lista para procesar por lotes desde esta pantalla.'
                : 'Validacion actualizada y lista para procesar por lotes desde esta pantalla.',
        ]);
    }

    private function validateRestartableValidationRun(ImportRun $importRun): ?JsonResponse
    {
        if ($importRun->run_type !== ImportRun::TYPE_VALIDATION || $importRun->mode !== ImportRun::MODE_VALIDATE_ONLY) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se puede actualizar una validacion de productos.',
            ], 422);
        }

        if (! $importRun->isTerminal()) {
            $isAsyncPreview = (bool) ($importRun->summary['preview_async'] ?? false);

            return response()->json([
                'success' => false,
                'message' => $isAsyncPreview
                    ? 'La previsualizacion anterior sigue en proceso. Espere a que termine antes de volver a previsualizar.'
                    : 'La validacion anterior sigue en proceso. Espere a que termine antes de volver a validarla.',
            ], 409);
        }

        return null;
    }

    public function runs(Request $request): JsonResponse
    {
        $this->reconcileOrphanedImportRuns($request->user());

        $query = ImportRun::query()
            ->with('user:id,name')
            ->where('entity', 'products')
            ->visibleTo($request->user());

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('run_type')) {
            $query->where('run_type', $request->string('run_type')->toString());
        }

        if ($request->filled('mode')) {
            $query->where('mode', $request->string('mode')->toString());
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->string('date_to')->toString());
        }

        if ($request->filled('file')) {
            $query->where('original_filename', 'LIKE', '%'.$request->string('file')->toString().'%');
        }

        if ($request->has('has_errors')) {
            $request->boolean('has_errors')
                ? $query->where('error_count', '>', 0)
                : $query->where('error_count', 0);
        }

        $query->orderByDesc('created_at')->orderByDesc('id');

        $perPage = min(max($request->integer('per_page', 10), 1), 50);
        $page = max($request->integer('page', 1), 1);

        if ($request->has('can_retry_failed')) {
            $shouldRetry = $request->boolean('can_retry_failed');

            $query->where(function ($query) use ($shouldRetry): void {
                if ($shouldRetry) {
                    $query->whereIn('status', ImportRun::TERMINAL_STATUSES)
                        ->where(function ($query): void {
                            $query->where('error_count', '>', 0)
                                ->orWhereNotNull('failed_rows');
                        });

                    return;
                }

                $query->whereNotIn('status', ImportRun::TERMINAL_STATUSES)
                    ->orWhere(function ($query): void {
                        $query->whereIn('status', ImportRun::TERMINAL_STATUSES)
                            ->where('error_count', 0)
                            ->whereNull('failed_rows');
                    });
            });
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginator->getCollection()
                ->map(fn (ImportRun $importRun): array => $this->importRunSummary($importRun))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function run(Request $request, ImportRun $importRun): JsonResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());
        $this->reconcileImportRun($importRun);

        return response()->json([
            'success' => true,
            'data' => $this->importRunDetail($importRun),
        ]);
    }

    public function processNow(Request $request, ImportRun $importRun): JsonResponse
    {
        return $this->processChunk($request, $importRun);
    }

    public function processChunk(Request $request, ImportRun $importRun): JsonResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());

        $importModes = [
            ImportRun::MODE_CREATE_UPDATE,
            ImportRun::MODE_CREATE_ONLY,
            ImportRun::MODE_UPDATE_STOCK_PRICE,
            ImportRun::MODE_RETRY_FAILED,
        ];
        $isValidationRun = $importRun->run_type === ImportRun::TYPE_VALIDATION
            && $importRun->mode === ImportRun::MODE_VALIDATE_ONLY;
        $isImportRun = $importRun->run_type === ImportRun::TYPE_IMPORT
            && in_array($importRun->mode, $importModes, true);

        if (! $isValidationRun && ! $isImportRun) {
            return response()->json([
                'success' => false,
                'message' => 'Este import no se puede procesar por chunks.',
            ], 422);
        }

        if ($response = $this->activeRunLimitResponse($request, $importRun->id)) {
            return $response;
        }

        $result = $this->chunkProcessor->process($importRun);
        $processedRun = $result['import_run'];

        return response()->json([
            'success' => true,
            'data' => array_merge($this->importRunDetail($processedRun), [
                'chunk' => [
                    'processed_rows' => $result['processed_rows'],
                    'has_more' => $result['has_more'],
                ],
            ]),
        ]);
    }

    public function errors(Request $request, ImportRun $importRun): BinaryFileResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $headers = ['Fila', 'Hoja', 'Producto', 'SKU', 'System ID', 'Accion prevista', 'Columna', 'Error', 'Valor actual', 'Valor importado', 'Sugerencia'];

        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }

        foreach ($this->exportableErrors($importRun) as $rowIndex => $error) {
            $data = $error['data'] ?? [];
            $sheet->setCellValue([1, $rowIndex + 2], $error['row'] ?? '');
            $sheet->setCellValue([2, $rowIndex + 2], $error['sheet_name'] ?? $importRun->sheet_name ?? '');
            $sheet->setCellValue([3, $rowIndex + 2], $error['label'] ?? ($data['nombre'] ?? ''));
            $sheet->setCellValue([4, $rowIndex + 2], $data['sku'] ?? '');
            $sheet->setCellValue([5, $rowIndex + 2], $data['system_id'] ?? '');
            $change = collect($error['change_preview'] ?? [])->first();
            $sheet->setCellValue([6, $rowIndex + 2], $error['action_type'] ?? ($data['action_type'] ?? $data['__action_type'] ?? ''));
            $sheet->setCellValue([7, $rowIndex + 2], $error['column'] ?? ($change['label'] ?? $change['field'] ?? ''));
            $sheet->setCellValue([8, $rowIndex + 2], $error['error'] ?? '');
            $sheet->setCellValue([9, $rowIndex + 2], $change['current'] ?? '');
            $sheet->setCellValue([10, $rowIndex + 2], $change['imported'] ?? ($error['value'] ?? ''));
            $sheet->setCellValue([11, $rowIndex + 2], $error['suggestion'] ?? collect($error['conflicts'] ?? [])->pluck('message')->implode(' '));
        }

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, "errores-importacion-{$importRun->id}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function rows(Request $request, ImportRun $importRun): JsonResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());

        $paginator = $this->importRunRowsQuery($request, $importRun)
            ->orderBy('row_number')
            ->paginate($this->rowsPerPage($request));

        $counts = $this->rowService->summaryCounts($importRun);
        $summary = $importRun->summary ?? [];
        $serverScopeActions = [
            'selected' => true,
            'page' => true,
            'filtered' => $paginator->total() <= 200,
            'all' => $paginator->total() <= 200,
        ];

        return response()->json([
            'success' => true,
            'data' => $paginator->getCollection()
                ->map(fn (ImportRunRow $row): array => $this->importRunRowPayload($row))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
                'rows_revision' => (int) ($summary['rows_revision'] ?? 0),
                'validated_rows_revision' => (int) ($summary['validated_rows_revision'] ?? 0),
                'rows_dirty' => (bool) ($summary['rows_dirty'] ?? false),
                'full_revalidation_status' => $summary['full_revalidation_status'] ?? 'completed',
                'valid_count' => (int) ($counts['row_status_counts']['valid'] ?? 0),
                'error_count' => (int) ($counts['row_status_counts']['error'] ?? 0),
                'warning_count' => (int) ($counts['warning_count'] ?? 0),
                'conflict_count' => (int) ($counts['conflict_count'] ?? 0),
                'action_counts' => $counts['action_counts'] ?? [],
                'retryable_failed_rows_count' => (int) ($counts['retryable_failed_rows_count'] ?? 0),
                'server_scope_actions' => $serverScopeActions,
                'revalidation_progress' => [
                    'status' => $summary['full_revalidation_status'] ?? 'completed',
                    'processed_rows' => (int) $importRun->processed_rows,
                    'total_rows' => (int) $importRun->total_rows,
                    'rows_revision' => (int) ($summary['rows_revision'] ?? 0),
                    'validated_rows_revision' => (int) ($summary['validated_rows_revision'] ?? 0),
                ],
                'counts' => $counts,
            ],
        ]);
    }

    public function updateRows(UpdateProductImportRunRowsRequest $request, ImportRun $importRun): JsonResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());
        $data = $this->rowService->updateRows($importRun, $request->validated());

        activity('product_import')
            ->causedBy($request->user())
            ->performedOn($importRun)
            ->event('rows_edited')
            ->withProperties($this->importRunActivityProperties($importRun, [
                'rows_count' => count($data['rows']),
                'rows_revision' => $data['rows_revision'],
                'source' => 'rows_patch',
            ]))
            ->log('Filas de validacion corregidas.');

        return response()->json([
            'success' => true,
            'data' => array_merge($data, [
                'rows' => collect($data['rows'])->map(fn (ImportRunRow $row): array => $this->importRunRowPayload($row))->values(),
            ]),
        ]);
    }

    public function updateMapping(UpdateProductImportRunMappingRequest $request, ImportRun $importRun): JsonResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());

        $data = $this->rowService->applyOperationalMapping(
            $importRun,
            $request->validated('mapping'),
            $request->validated('visible_row_ids') ?? [],
            (int) $request->validated('rows_revision')
        );

        if (($data['requires_full_restart'] ?? false) === true) {
            return response()->json([
                'success' => false,
                'message' => $data['message'] ?? 'El cambio de mapping requiere reiniciar la validacion completa.',
                'data' => [
                    'requires_full_restart' => true,
                ],
            ], 409);
        }

        RevalidateProductImportRunRows::dispatch($importRun->id, $request->user()?->id);
        $importRun->refresh();

        activity('product_import')
            ->causedBy($request->user())
            ->performedOn($importRun)
            ->event('mapping_operational_updated')
            ->withProperties($this->importRunActivityProperties($importRun, [
                'rows_revision' => (int) $data['rows_revision'],
                'visible_rows_count' => collect($data['rows'])->count(),
                'source' => 'operational_mapping',
            ]))
            ->log('Mapping operativo de importacion actualizado.');

        return response()->json([
            'success' => true,
            'data' => [
                'import_run_id' => $importRun->id,
                'mapping' => $importRun->mapping ?? [],
                'rows_revision' => $data['rows_revision'],
                'rows_dirty' => $data['rows_dirty'],
                'full_revalidation_status' => $data['full_revalidation_status'],
                'rows' => collect($data['rows'])->map(fn (ImportRunRow $row): array => $this->importRunRowPayload($row))->values(),
            ],
            'message' => 'Mapping operativo aplicado. Revalidacion completa en cola.',
        ]);
    }

    public function revalidateRows(Request $request, ImportRun $importRun): JsonResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());

        if (
            $importRun->run_type !== ImportRun::TYPE_VALIDATION
            || $importRun->mode !== ImportRun::MODE_VALIDATE_ONLY
            || $importRun->status !== ImportRun::STATUS_COMPLETED
        ) {
            return response()->json(['success' => false, 'message' => 'La validacion seleccionada no admite revalidacion.'], 409);
        }

        if (! $importRun->rows()->exists()) {
            return response()->json(['success' => false, 'message' => 'La validacion seleccionada no tiene filas persistidas para revalidar.'], 409);
        }

        $summary = $importRun->summary ?? [];
        $currentStatus = (string) ($summary['full_revalidation_status'] ?? 'completed');

        if (in_array($currentStatus, ['pending', 'running'], true)) {
            return response()->json([
                'success' => true,
                'data' => ['full_revalidation_status' => $currentStatus],
            ]);
        }

        if ($response = $this->activeRunLimitResponse($request)) {
            return $response;
        }

        $importRun->update(['summary' => array_merge($summary, [
            'full_revalidation_status' => 'pending',
            'revalidation_requested_by_user_id' => $request->user()?->id,
        ])]);
        RevalidateProductImportRunRows::dispatch($importRun->id, $request->user()?->id);
        $this->recordImportRunActivity($importRun, $request, 'rows_revalidation_requested', 'Revalidacion completa de filas solicitada.');

        return response()->json([
            'success' => true,
            'data' => ['full_revalidation_status' => 'pending'],
        ]);
    }

    public function errorRows(Request $request, ImportRun $importRun): JsonResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());

        $request->merge(['has_errors' => true]);

        return $this->rows($request, $importRun);
    }

    public function source(Request $request, ImportRun $importRun): BinaryFileResponse|JsonResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());

        if (! $this->sourceFileExists($importRun)) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontro el archivo original de esta importacion.',
            ], 404);
        }

        return response()->download(
            Storage::disk('local')->path($importRun->stored_path),
            $importRun->original_filename ?: basename((string) $importRun->stored_path)
        );
    }

    public function retryFailed(Request $request, ImportRun $importRun): JsonResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());

        if ($response = $this->activeRunLimitResponse($request)) {
            return $response;
        }

        if (in_array($importRun->status, [ImportRun::STATUS_PENDING, ImportRun::STATUS_RUNNING, ImportRun::STATUS_CANCEL_REQUESTED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede reintentar un import en proceso.',
            ], 409);
        }

        $failedRows = $this->failedRowsForRetry($importRun);
        if ($failedRows === []) {
            return response()->json([
                'success' => false,
                'message' => 'No hay filas fallidas con datos para reintentar.',
            ], 422);
        }

        $hasActiveRetry = ImportRun::query()
            ->where('parent_import_run_id', $importRun->id)
            ->whereIn('status', [ImportRun::STATUS_PENDING, ImportRun::STATUS_RUNNING])
            ->exists();

        if ($hasActiveRetry) {
            return response()->json([
                'success' => false,
                'message' => 'Ya existe un reintento en proceso para este import.',
            ], 409);
        }

        $retryRun = ImportRun::query()->create([
            'user_id' => $request->user()?->id,
            'entity' => $importRun->entity,
            'parent_import_run_id' => $importRun->id,
            'original_filename' => $importRun->original_filename,
            'stored_path' => null,
            'sheet_index' => $importRun->sheet_index,
            'sheet_name' => $importRun->sheet_name,
            'run_type' => ImportRun::TYPE_IMPORT,
            'mode' => ImportRun::MODE_RETRY_FAILED,
            'mapping' => $importRun->mapping ?? [],
            'failed_rows' => $failedRows,
            'status' => ImportRun::STATUS_PENDING,
            'phase' => ImportRun::PHASE_QUEUED,
            'results' => [],
            'errors' => [],
            'summary' => [],
        ]);

        $this->recordImportRunActivity($retryRun, $request, 'retry_created', 'Reintento de filas fallidas creado.');

        return response()->json([
            'success' => true,
            'data' => [
                'import_run_id' => $retryRun->id,
                'status' => $retryRun->status,
                'chunk_processing' => true,
                'process_chunk_url' => "/api/v1/products/import/runs/{$retryRun->id}/process-chunk",
            ],
        ]);
    }

    public function cancel(Request $request, ImportRun $importRun): JsonResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());

        if ($importRun->canCancel()) {
            if ($importRun->status === ImportRun::STATUS_PENDING) {
                $importRun->update([
                    'status' => ImportRun::STATUS_CANCELLED,
                    'finished_at' => now(),
                    'summary' => array_merge($importRun->summary ?? [], [
                        'cancelled_before_start' => true,
                        'source_file_deleted' => false,
                    ]),
                ]);

                $this->recordImportRunActivity(
                    $importRun,
                    $request,
                    'cancelled',
                    'Importacion cancelada antes de iniciar.'
                );

                return response()->json([
                    'success' => true,
                    'data' => ['status' => $importRun->refresh()->status],
                    'message' => 'Importacion cancelada antes de ser tomada por la cola.',
                ]);
            }

            if (($importRun->summary['chunk_processing'] ?? false) === true) {
                $importRun->update([
                    'status' => ImportRun::STATUS_CANCELLED,
                    'phase' => ImportRun::PHASE_FINALIZING,
                    'finished_at' => now(),
                    'heartbeat_at' => now(),
                    'summary' => array_merge($importRun->summary ?? [], [
                        'cancelled_from_ui_chunks' => true,
                        'source_file_deleted' => false,
                    ]),
                ]);

                $this->recordImportRunActivity(
                    $importRun,
                    $request,
                    'cancelled',
                    'Importacion cancelada desde procesamiento por lotes.'
                );

                return response()->json([
                    'success' => true,
                    'data' => ['status' => $importRun->refresh()->status],
                    'message' => 'Importacion cancelada.',
                ]);
            }

            $importRun->update(['status' => ImportRun::STATUS_CANCEL_REQUESTED]);
            $this->recordImportRunActivity(
                $importRun,
                $request,
                'cancel_requested',
                'Cancelacion de importacion solicitada.'
            );
        }

        return response()->json([
            'success' => true,
            'data' => ['status' => $importRun->refresh()->status],
            'message' => 'Cancelacion solicitada. El proceso se detendra al terminar el bloque actual.',
        ]);
    }

    public function destroy(Request $request, ImportRun $importRun): JsonResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());

        if (! $importRun->canDeleteFromHistory()) {
            return response()->json([
                'success' => false,
                'message' => $importRun->deleteBlockReason(),
            ], 409);
        }

        $storedPath = $importRun->stored_path;

        DB::transaction(function () use ($importRun): void {
            $importRun->records()->delete();
            $importRun->delete();
        });

        activity('product_import')
            ->causedBy($request->user())
            ->performedOn($importRun)
            ->event('deleted')
            ->withProperties($this->importRunActivityProperties($importRun, [
                'import_run_id' => $importRun->id,
                'status' => $importRun->status,
                'source' => 'history_delete',
            ]))
            ->log('Importacion eliminada del historial.');

        if (is_string($storedPath) && $storedPath !== '' && ! $this->storedPathIsReferenced($storedPath)) {
            Storage::disk('local')->delete($storedPath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Importacion eliminada del historial.',
        ]);
    }

    public function profiles(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ImportProfile::query()
                ->where('entity', 'products')
                ->visibleTo($request->user())
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeProfile(StoreImportProfileRequest $request): JsonResponse
    {
        $data = $request->validated();

        $profile = ImportProfile::query()->updateOrCreate([
            'entity' => 'products',
            'user_id' => $request->user()?->id,
            'name' => $data['name'],
        ], [
            'mapping' => $data['mapping'] ?? [],
            'sheet_index' => $data['sheet_index'] ?? null,
            'sheet_name' => $data['sheet_name'] ?? null,
            'mode' => $data['mode'] ?? ImportRun::MODE_CREATE_UPDATE,
            'grouping_rules' => $data['grouping_rules'] ?? [],
            'defaults' => $data['defaults'] ?? [],
        ]);

        activity('product_import')
            ->causedBy($request->user())
            ->performedOn($profile)
            ->event('saved')
            ->withProperties(['entity' => 'products'])
            ->log('Perfil de importacion guardado.');

        return response()->json([
            'success' => true,
            'data' => $profile,
        ], 201);
    }

    private function failedRowsForRetry(ImportRun $importRun): array
    {
        $rows = $importRun->rows()->where('status', 'error')->get()
            ->map(fn (ImportRunRow $row): array => $this->importRunRowPayload($row))
            ->filter(fn (array $row): bool => ! empty($row['data']))
            ->values()
            ->all();

        if ($rows === []) {
            $rows = $importRun->failed_rows ?: $importRun->errors ?: [];
        }

        return collect($rows)
            ->filter(fn ($row): bool => is_array($row)
                && ! empty($row['data'])
                && is_array($row['data']))
            ->values()
            ->all();
    }

    private function exportableErrors(ImportRun $importRun): array
    {
        $persistedRows = $importRun->rows()
            ->where('status', 'error')
            ->orderBy('row_number')
            ->get()
            ->map(fn (ImportRunRow $row): array => $this->importRunRowPayload($row))
            ->all();

        if ($persistedRows !== []) {
            return $persistedRows;
        }

        $errors = $importRun->errors ?? [];
        $failedRows = $this->failedRowsForRetry($importRun);

        if (count($failedRows) > count($errors)) {
            return $failedRows;
        }

        return $errors;
    }

    private function importRunRowsQuery(Request $request, ImportRun $importRun): \Illuminate\Database\Eloquent\Builder
    {
        $query = $importRun->rows()->getQuery();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('action_type')) {
            $query->where('action_type', $request->string('action_type')->toString());
        }

        if ($request->boolean('has_errors')) {
            $query->where('status', 'error');
        }

        if ($request->boolean('has_conflicts')) {
            $query->whereNotNull('conflicts')
                ->whereRaw("CAST(conflicts AS TEXT) != '[]'");
        }

        if ($request->boolean('has_warnings')) {
            $query->whereNotNull('warnings')
                ->whereRaw("CAST(warnings AS TEXT) != '[]'");
        }

        if ($request->filled('search')) {
            $search = mb_strtolower($request->string('search')->toString());
            $query->where(function ($query) use ($search): void {
                $query->where('search_text', 'LIKE', "%{$search}%")
                    ->orWhere(function ($query) use ($search): void {
                        $query->whereNull('search_text')
                            ->where(function ($query) use ($search): void {
                                $query->where('label', 'LIKE', "%{$search}%")
                                    ->orWhereRaw('CAST(data AS TEXT) LIKE ?', ["%{$search}%"]);
                            });
                    });
            });
        }

        return $query;
    }

    private function rowsPerPage(Request $request): int
    {
        return min(max($request->integer('per_page', 50), 1), 200);
    }

    private function importRunRowPayload(ImportRunRow $row): array
    {
        $errors = $row->errors ?? [];
        $data = $row->data ?? [];
        unset($data['imagen_url']);

        return [
            'id' => $row->id,
            'row' => $row->row_number,
            'sheet_name' => $row->sheet_name,
            'status' => $row->status,
            'is_valid' => $row->status !== 'error',
            'action_type' => $row->action_type,
            'label' => $row->label,
            'data' => $data,
            'errors' => $errors,
            'warnings' => $row->warnings ?? [],
            'conflicts' => $row->conflicts ?? [],
            'change_preview' => $row->change_preview ?? [],
            'error' => collect($errors)->implode(' | '),
            'updated_at' => $row->updated_at,
            'is_edited' => (bool) $row->is_edited,
        ];
    }

    private function visibleValidationRows(array $rows): array
    {
        return collect($rows)
            ->map(function (array $row): array {
                unset($row['source_data']);

                if (isset($row['data']) && is_array($row['data'])) {
                    unset($row['data']['imagen_url']);
                }

                return $row;
            })
            ->values()
            ->all();
    }

    private function importRunSummary(ImportRun $importRun): array
    {
        $hasSourceFile = $this->sourceFileExists($importRun);
        $summary = $importRun->summary ?? [];
        $retryableFailedRowsCount = array_key_exists('retryable_failed_rows_count', $summary)
            ? (int) $summary['retryable_failed_rows_count']
            : $this->rowService->retryableFailedRowsCount($importRun);

        return array_merge([
            'id' => $importRun->id,
            'entity' => $importRun->entity,
            'original_filename' => $importRun->original_filename,
            'sheet_index' => $importRun->sheet_index,
            'sheet_name' => $importRun->sheet_name,
            'run_type' => $importRun->run_type,
            'mode' => $importRun->mode,
            'status' => $importRun->status,
            'phase' => $importRun->phase,
            'total_rows' => $importRun->total_rows,
            'processed_rows' => $importRun->processed_rows,
            'success_count' => $importRun->success_count,
            'error_count' => $importRun->error_count,
            'progress_percent' => $this->progressPercent($importRun),
            'has_errors' => $importRun->error_count > 0,
            'has_source_file' => $hasSourceFile,
            'source_download_url' => $hasSourceFile ? "/api/v1/products/import/runs/{$importRun->id}/source" : null,
            'errors_download_url' => "/api/v1/products/import/runs/{$importRun->id}/errors.xlsx",
            'preview_async' => (bool) ($summary['preview_async'] ?? false),
            'action_counts' => $summary['action_counts'] ?? [],
            'conflict_count' => (int) ($summary['conflict_count'] ?? 0),
            'unchanged_count' => (int) ($summary['unchanged_count'] ?? 0),
            'can_delete' => $importRun->canDeleteFromHistory(),
            'delete_block_reason' => $importRun->deleteBlockReason(),
            'user' => $importRun->user ? [
                'id' => $importRun->user->id,
                'name' => $importRun->user->name,
            ] : null,
            'can_retry_failed' => $importRun->isTerminal() && $retryableFailedRowsCount > 0,
            'retryable_failed_rows_count' => $retryableFailedRowsCount,
            'can_cancel' => $importRun->canCancel(),
            'started_at' => $importRun->started_at,
            'finished_at' => $importRun->finished_at,
            'heartbeat_at' => $importRun->heartbeat_at,
            'created_at' => $importRun->created_at,
            'updated_at' => $importRun->updated_at,
        ], $this->queueWorkerMetadata($importRun));
    }

    private function importRunDetail(ImportRun $importRun): array
    {
        $summary = $importRun->summary ?? [];

        return array_merge($this->importRunSummary($importRun), [
            'mapping' => $importRun->mapping ?? [],
            'results' => $importRun->results ?? [],
            'errors' => $importRun->errors ?? [],
            'summary' => $summary,
            'validation_rows' => [],
            'validation_rows_count' => $summary['validation_rows_count'] ?? 0,
            'validation_rows_limited' => (bool) ($summary['validation_rows_limited'] ?? ($summary['validation_rows_count'] ?? 0) > 0),
        ]);
    }

    private function progressPercent(ImportRun $importRun): int
    {
        if ($importRun->total_rows <= 0) {
            return $importRun->isTerminal() ? 100 : 0;
        }

        return (int) min(
            100,
            max(0, round(($importRun->processed_rows / $importRun->total_rows) * 100))
        );
    }

    private function abortUnlessVisibleImportRun(ImportRun $importRun, User $user): void
    {
        abort_unless($user->isAdmin() || $importRun->user_id === $user->id, 404);
    }

    private function reconcileOrphanedImportRuns(User $user): void
    {
        $this->runReconciler->reconcileOrphaned($user);
    }

    private function reconcileImportRun(ImportRun $importRun): bool
    {
        return $this->runReconciler->reconcile($importRun) !== null;
    }

    /**
     * @return array{waiting_for_worker: bool, worker_required?: bool, blocking_reason?: string, worker_message?: string, queue?: string, queue_connection?: string, queue_driver?: mixed, worker_command?: string, worker_auto_start_enabled?: bool, worker_auto_start_status?: string, worker_auto_start_error?: string, worker_php_binary?: string, worker_process_id?: int|null, worker_start_verified?: bool}
     */
    private function queueWorkerMetadata(ImportRun $importRun): array
    {
        return [
            'queue' => (string) config('queue.connections.product_imports.queue', 'product-imports'),
            'queue_connection' => 'product_imports',
            'queue_driver' => config('queue.connections.product_imports.driver'),
            'worker_command' => $this->workerSupervisor->commandDisplay(),
            'waiting_for_worker' => false,
            'worker_required' => false,
            'chunk_processing' => true,
            'process_chunk_url' => "/api/v1/products/import/runs/{$importRun->id}/process-chunk",
        ];
    }

    /**
     * @param  array{worker_auto_start_status?: string}  $workerStart
     */
    private function workerBlockingReason(array $workerStart): string
    {
        return in_array($workerStart['worker_auto_start_status'] ?? null, ['failed', 'startup_unconfirmed'], true)
            ? 'worker_auto_start_failed'
            : 'worker_not_detected';
    }

    /**
     * @param  array{worker_auto_start_enabled?: bool, worker_auto_start_status?: string, worker_auto_start_error?: string}  $workerStart
     */
    private function workerMessage(array $workerStart): string
    {
        $status = $workerStart['worker_auto_start_status'] ?? null;

        if (in_array($status, ['started', 'already_active', 'already_requested'], true)) {
            return 'Worker product-imports solicitado automaticamente. El archivo continuara en cola.';
        }

        if (in_array($status, ['failed', 'startup_unconfirmed'], true)) {
            return 'No se pudo confirmar el arranque automatico del worker product-imports. Inicia la cola product-imports para procesar este archivo.';
        }

        return 'Esperando worker de importacion. Inicia la cola product-imports para procesar este archivo.';
    }

    private function productImportQueueUnavailableResponse(): ?JsonResponse
    {
        $driver = (string) config('queue.connections.product_imports.driver');
        if ($driver !== 'redis') {
            return null;
        }

        try {
            Redis::connection(config('queue.connections.product_imports.connection'))->ping();

            return null;
        } catch (\Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'La cola product-imports usa Redis, pero Redis no esta disponible. Cambia PRODUCT_IMPORT_QUEUE_DRIVER a database o levanta Redis antes de importar.',
                'data' => [
                    'blocking_reason' => 'queue_backend_unavailable',
                    'queue' => (string) config('queue.connections.product_imports.queue', 'product-imports'),
                    'queue_connection' => 'product_imports',
                    'queue_driver' => $driver,
                    'worker_required' => true,
                    'worker_command' => $this->workerSupervisor->commandDisplay(),
                ],
            ], 422);
        }
    }

    private function activeRunLimitResponse(Request $request, ?int $exceptImportRunId = null): ?JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        $this->reconcileOrphanedImportRuns($user);

        $activeRuns = ImportRun::query()
            ->where('entity', 'products')
            ->where('user_id', $user->id)
            ->whereIn('status', self::ACTIVE_RUN_STATUSES)
            ->when($exceptImportRunId !== null, fn ($query) => $query->whereKeyNot($exceptImportRunId))
            ->count();

        if ($activeRuns < self::MAX_ACTIVE_RUNS_PER_USER) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Ya tienes procesos de importacion activos. Espera a que terminen o cancela uno antes de iniciar otro.',
            'data' => [
                'active_runs_count' => $activeRuns,
                'active_runs_limit' => self::MAX_ACTIVE_RUNS_PER_USER,
            ],
        ], 409);
    }

    private function recordImportRunActivity(ImportRun $importRun, Request $request, string $event, string $description, array $properties = []): void
    {
        activity('product_import')
            ->causedBy($request->user())
            ->performedOn($importRun)
            ->event($event)
            ->withProperties($this->importRunActivityProperties($importRun, $properties))
            ->log($description);
    }

    private function importRunActivityProperties(ImportRun $importRun, array $properties = []): array
    {
        $summary = $importRun->summary ?? [];

        return array_merge([
            'import_run_id' => $importRun->id,
            'parent_import_run_id' => $importRun->parent_import_run_id,
            'user_id' => $importRun->user_id,
            'entity' => $importRun->entity,
            'run_type' => $importRun->run_type,
            'mode' => $importRun->mode,
            'status' => $importRun->status,
            'rows_revision' => (int) ($summary['rows_revision'] ?? $summary['source_rows_revision'] ?? 0),
            'rows_count' => (int) ($summary['validation_rows_count'] ?? $importRun->total_rows),
            'error_count' => (int) $importRun->error_count,
            'source' => $summary['force_without_preview'] ?? false ? 'force_without_preview' : 'products_import',
        ], $properties);
    }

    private function copyValidatedImportSource(ImportRun $validationRun): ?string
    {
        if (! is_string($validationRun->stored_path) || $validationRun->stored_path === '') {
            return null;
        }

        $extension = pathinfo($validationRun->stored_path, PATHINFO_EXTENSION);
        $targetPath = 'imports/'.Str::uuid().($extension !== '' ? ".{$extension}" : '');

        return Storage::disk('local')->copy($validationRun->stored_path, $targetPath)
            ? $targetPath
            : null;
    }

    private function storedPathIsReferenced(string $storedPath): bool
    {
        return ImportRun::query()
            ->where('stored_path', $storedPath)
            ->exists();
    }

    private function dispatchProductImportRun(ImportRun $importRun): void
    {
        ProcessProductImportRun::dispatch($importRun->id)
            ->onConnection('product_imports')
            ->onQueue((string) config('queue.connections.product_imports.queue', 'product-imports'))
            ->afterCommit();

        $this->workerSupervisor->ensureRunningAfterCommit();
    }

    private function sourceFileExists(ImportRun $importRun): bool
    {
        return is_string($importRun->stored_path)
            && $importRun->stored_path !== ''
            && Storage::disk('local')->exists($importRun->stored_path);
    }
}
