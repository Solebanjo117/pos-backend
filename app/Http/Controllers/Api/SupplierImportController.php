<?php

namespace App\Http\Controllers\Api;

use App\Exports\SupplierImportTemplate;
use App\Http\Controllers\Controller;
use App\Imports\SupplierImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupplierImportController extends Controller
{
    public function template(): BinaryFileResponse
    {
        return Excel::download(new SupplierImportTemplate, 'plantilla_proveedores.xlsx');
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

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

    public function execute(Request $request): JsonResponse
    {
        $request->validate([
            'rows' => 'required|array',
            'rows.*.__row' => 'required|integer',
        ]);

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
