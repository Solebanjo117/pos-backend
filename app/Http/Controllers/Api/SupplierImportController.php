<?php

namespace App\Http\Controllers\Api;

use App\Exports\SupplierImportTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteImportRequest;
use App\Http\Requests\PreviewImportRequest;
use App\Imports\SupplierImport;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupplierImportController extends Controller
{
    public function template(): BinaryFileResponse
    {
        return Excel::download(new SupplierImportTemplate, 'plantilla_proveedores.xlsx');
    }

    public function preview(PreviewImportRequest $request): JsonResponse
    {
        $import = new SupplierImport;
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
        $import = new SupplierImport;
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
