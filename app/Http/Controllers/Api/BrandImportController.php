<?php

namespace App\Http\Controllers\Api;

use App\Exports\BrandImportTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteImportRequest;
use App\Http\Requests\PreviewImportRequest;
use App\Imports\BrandImport;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandImportController extends Controller
{
    public function template(): BinaryFileResponse
    {
        return Excel::download(new BrandImportTemplate, 'plantilla_marcas.xlsx');
    }

    public function preview(PreviewImportRequest $request): JsonResponse
    {
        $import = new BrandImport;
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
        $import = new BrandImport;
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
}
