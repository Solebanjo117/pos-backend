<?php

namespace App\Http\Controllers\Api;

use App\Exports\ProductImportTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteImportRequest;
use App\Http\Requests\PreviewImportRequest;
use App\Http\Requests\StoreImportProfileRequest;
use App\Imports\ProductImport;
use App\Jobs\ProcessProductImportRun;
use App\Models\ImportProfile;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductImportController extends Controller
{
    public function __construct(private readonly ProductService $productService) {}

    public function template(): BinaryFileResponse
    {
        return Excel::download(new ProductImportTemplate, 'plantilla_productos.xlsx');
    }

    public function preview(PreviewImportRequest $request): JsonResponse
    {
        $import = new ProductImport($this->productService);
        try {
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

        return response()->json([
            'success' => true,
            'data' => [
                'rows' => $preview['rows'],
                'count' => count($preview['rows']),
                'shown_count' => count($preview['rows']),
                'max_rows' => $preview['max_rows'],
                'preview_limited' => false,
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
        $import = new ProductImport($this->productService);
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

    public function import(PreviewImportRequest $request): JsonResponse
    {
        return $this->startFileRun($request, ImportRun::TYPE_IMPORT, $request->input('mode', ImportRun::MODE_CREATE_UPDATE));
    }

    public function validateFile(PreviewImportRequest $request): JsonResponse
    {
        return $this->startFileRun($request, ImportRun::TYPE_VALIDATION, ImportRun::MODE_VALIDATE_ONLY);
    }

    private function startFileRun(PreviewImportRequest $request, string $runType, string $mode): JsonResponse
    {
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

        if ($sheetName === null || trim((string) $sheetName) === '') {
            try {
                $selectedSheet = (new ProductImport($this->productService))->resolveSelectedSheet($file, $sheetIndex, null, $mapping);
                $sheetIndex = $selectedSheet['index'];
                $sheetName = $selectedSheet['name'];
            } catch (\RuntimeException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        }

        $storedPath = $file->store('imports');

        if (! is_string($storedPath)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo guardar el archivo para importarlo.',
            ], 500);
        }

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
            'mapping' => $mapping,
            'status' => ImportRun::STATUS_PENDING,
            'results' => [],
            'errors' => [],
            'summary' => [],
        ]);

        ProcessProductImportRun::dispatch($importRun->id);

        return response()->json([
            'success' => true,
            'data' => [
                'import_run_id' => $importRun->id,
                'status' => $importRun->status,
                'mode' => $importRun->mode,
            ],
            'message' => 'Importacion en proceso. Puede consultar el avance desde esta pantalla.',
        ]);
    }

    public function runs(Request $request): JsonResponse
    {
        $query = ImportRun::query()
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
            $runs = $query->get()
                ->filter(fn (ImportRun $run): bool => ($run->isTerminal() && $this->failedRowsForRetry($run) !== []) === $shouldRetry)
                ->values();

            $paginator = new LengthAwarePaginator(
                $runs->forPage($page, $perPage)->values(),
                $runs->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        } else {
            $paginator = $query->paginate($perPage);
        }

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

        return response()->json([
            'success' => true,
            'data' => $this->importRunDetail($importRun),
        ]);
    }

    public function processNow(Request $request, ImportRun $importRun): JsonResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());

        if ($importRun->run_type !== ImportRun::TYPE_VALIDATION || $importRun->mode !== ImportRun::MODE_VALIDATE_ONLY) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se puede procesar inmediatamente una validacion completa pendiente.',
            ], 422);
        }

        if ($importRun->status !== ImportRun::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'La validacion ya fue tomada por la cola o ya finalizo.',
            ], 409);
        }

        if (! is_string($importRun->stored_path) || $importRun->stored_path === '' || ! Storage::disk('local')->exists($importRun->stored_path)) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontro el archivo almacenado para validar.',
            ], 422);
        }

        (new ProcessProductImportRun($importRun->id))->handle($this->productService);
        $importRun->refresh();

        return response()->json([
            'success' => true,
            'data' => $this->importRunDetail($importRun),
        ]);
    }

    public function errors(Request $request, ImportRun $importRun): BinaryFileResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $headers = ['Fila', 'Hoja', 'Producto', 'SKU', 'System ID', 'Columna', 'Error', 'Valor', 'Sugerencia'];

        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }

        foreach (($importRun->errors ?? []) as $rowIndex => $error) {
            $data = $error['data'] ?? [];
            $sheet->setCellValue([1, $rowIndex + 2], $error['row'] ?? '');
            $sheet->setCellValue([2, $rowIndex + 2], $importRun->sheet_name ?? '');
            $sheet->setCellValue([3, $rowIndex + 2], $error['label'] ?? ($data['nombre'] ?? ''));
            $sheet->setCellValue([4, $rowIndex + 2], $data['sku'] ?? '');
            $sheet->setCellValue([5, $rowIndex + 2], $data['system_id'] ?? '');
            $sheet->setCellValue([6, $rowIndex + 2], $error['column'] ?? '');
            $sheet->setCellValue([7, $rowIndex + 2], $error['error'] ?? '');
            $sheet->setCellValue([8, $rowIndex + 2], $error['value'] ?? '');
            $sheet->setCellValue([9, $rowIndex + 2], $error['suggestion'] ?? '');
        }

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, "errores-importacion-{$importRun->id}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function retryFailed(Request $request, ImportRun $importRun): JsonResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());

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
            'results' => [],
            'errors' => [],
            'summary' => [],
        ]);

        ProcessProductImportRun::dispatch($retryRun->id);

        return response()->json([
            'success' => true,
            'data' => [
                'import_run_id' => $retryRun->id,
                'status' => $retryRun->status,
            ],
        ]);
    }

    public function cancel(Request $request, ImportRun $importRun): JsonResponse
    {
        $this->abortUnlessVisibleImportRun($importRun, $request->user());

        if ($importRun->canCancel()) {
            $importRun->update(['status' => ImportRun::STATUS_CANCEL_REQUESTED]);
        }

        return response()->json([
            'success' => true,
            'data' => ['status' => $importRun->refresh()->status],
            'message' => 'Cancelacion solicitada. El proceso se detendra al terminar el bloque actual.',
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

        return response()->json([
            'success' => true,
            'data' => $profile,
        ], 201);
    }

    private function failedRowsForRetry(ImportRun $importRun): array
    {
        $rows = $importRun->failed_rows ?: $importRun->errors ?: [];

        return collect($rows)
            ->filter(fn ($row): bool => is_array($row) && ! empty($row['data']) && is_array($row['data']))
            ->values()
            ->all();
    }

    private function importRunSummary(ImportRun $importRun): array
    {
        return [
            'id' => $importRun->id,
            'entity' => $importRun->entity,
            'original_filename' => $importRun->original_filename,
            'sheet_index' => $importRun->sheet_index,
            'sheet_name' => $importRun->sheet_name,
            'run_type' => $importRun->run_type,
            'mode' => $importRun->mode,
            'status' => $importRun->status,
            'total_rows' => $importRun->total_rows,
            'processed_rows' => $importRun->processed_rows,
            'success_count' => $importRun->success_count,
            'error_count' => $importRun->error_count,
            'progress_percent' => $this->progressPercent($importRun),
            'has_errors' => $importRun->error_count > 0,
            'has_source_file' => is_string($importRun->stored_path) && Storage::disk('local')->exists($importRun->stored_path),
            'can_retry_failed' => $importRun->isTerminal() && $this->failedRowsForRetry($importRun) !== [],
            'can_cancel' => $importRun->canCancel(),
            'started_at' => $importRun->started_at,
            'finished_at' => $importRun->finished_at,
            'created_at' => $importRun->created_at,
            'updated_at' => $importRun->updated_at,
        ];
    }

    private function importRunDetail(ImportRun $importRun): array
    {
        $summary = $importRun->summary ?? [];

        return array_merge($this->importRunSummary($importRun), [
            'mapping' => $importRun->mapping ?? [],
            'results' => $importRun->results ?? [],
            'errors' => $importRun->errors ?? [],
            'summary' => $summary,
            'validation_rows' => $summary['validation_rows'] ?? [],
            'validation_rows_count' => $summary['validation_rows_count'] ?? 0,
            'validation_rows_limited' => $summary['validation_rows_limited'] ?? false,
        ]);
    }

    private function progressPercent(ImportRun $importRun): int
    {
        if ($importRun->total_rows <= 0) {
            return $importRun->isTerminal() ? 100 : 0;
        }

        return (int) min(100, max(0, round(($importRun->processed_rows / $importRun->total_rows) * 100)));
    }

    private function abortUnlessVisibleImportRun(ImportRun $importRun, User $user): void
    {
        abort_unless($user->isAdmin() || $importRun->user_id === $user->id, 404);
    }
}
