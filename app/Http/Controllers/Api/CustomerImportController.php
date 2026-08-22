<?php

namespace App\Http\Controllers\Api;

use App\Exports\CustomerImportTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteImportRequest;
use App\Http\Requests\PreviewImportRequest;
use App\Imports\CustomerImport;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CustomerImportController extends Controller
{
    public function template(): BinaryFileResponse
    {
        return Excel::download(new CustomerImportTemplate, 'plantilla_clientes.xlsx');
    }

    public function preview(PreviewImportRequest $request): JsonResponse
    {
        $rows = (new CustomerImport)->preview($request->file('file'));

        return response()->json(['success' => true, 'data' => ['rows' => $rows, 'count' => count($rows)]]);
    }

    public function execute(ExecuteImportRequest $request): JsonResponse
    {
        $import = new CustomerImport;
        $import->execute($request->input('rows', []));

        return response()->json(['success' => $import->errorCount === 0, 'data' => ['success_count' => $import->successCount, 'error_count' => $import->errorCount, 'results' => $import->results, 'errors' => $import->errors]], $import->errorCount ? 422 : 200);
    }
}
