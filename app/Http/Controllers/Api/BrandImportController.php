<?php

namespace App\Http\Controllers\Api;

use App\Exports\BrandImportTemplate;
use App\Http\Controllers\Controller;
use App\Imports\BrandImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandImportController extends Controller
{
    public function template(): BinaryFileResponse
    {
        return Excel::download(new BrandImportTemplate, 'plantilla_marcas.xlsx');
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

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

    public function execute(Request $request): JsonResponse
    {
        $request->validate([
            'rows' => 'required|array',
            'rows.*.__row' => 'required|integer',
        ]);

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
