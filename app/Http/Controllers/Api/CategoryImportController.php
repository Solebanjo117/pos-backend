<?php

namespace App\Http\Controllers\Api;

use App\Exports\CategoryImportTemplate;
use App\Http\Controllers\Controller;
use App\Imports\CategoryImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CategoryImportController extends Controller
{
    public function template(): BinaryFileResponse
    {
        return Excel::download(new CategoryImportTemplate, 'plantilla_categorias.xlsx');
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $import = new CategoryImport;
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

        $import = new CategoryImport;
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
