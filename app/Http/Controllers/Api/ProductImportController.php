<?php

namespace App\Http\Controllers\Api;

use App\Exports\ProductImportTemplate;
use App\Http\Controllers\Controller;
use App\Imports\ProductImport;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductImportController extends Controller
{
    public function __construct(private readonly ProductService $productService)
    {
    }

    /**
     * GET /api/v1/products/import/template
     */
    public function template(): BinaryFileResponse
    {
        return Excel::download(new ProductImportTemplate(), 'plantilla_productos.xlsx');
    }

    /**
     * POST /api/v1/products/import/preview
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

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

    /**
     * POST /api/v1/products/import
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

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
