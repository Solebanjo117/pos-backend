<?php

namespace App\Http\Controllers\Api;

use App\Exports\ProductImportTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteImportRequest;
use App\Http\Requests\PreviewImportRequest;
use App\Imports\ProductImport;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
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
        $rows = $import->preview($request->file('file'));

        return response()->json([
            'success' => true,
            'data' => [
                'rows' => $rows,
                'count' => count($rows),
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
        $import = new ProductImport($this->productService);
        $import->import($request->file('file'));

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
}
