<?php

namespace App\Http\Controllers\Api;

use App\Exports\VehicleCatalogExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkDeleteVehicleCatalogRequest;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\VehicleCatalogService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VehicleCatalogController extends Controller
{
    public function __construct(private VehicleCatalogService $catalogService) {}

    public function summary(): JsonResponse
    {
        return response()->json([
            'data' => [
                'makes' => VehicleMake::query()->count(),
                'engines' => VehicleEngine::query()->count(),
                'models' => VehicleModel::query()->count(),
                'vehicles' => $this->catalogService->configurationPairsQuery()->count(),
            ],
        ]);
    }

    public function bulkDelete(BulkDeleteVehicleCatalogRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $deleted = $this->catalogService->bulkDelete(
            $validated['entity'],
            $validated['ids'] ?? [],
            $validated['configurations'] ?? [],
        );

        return response()->json([
            'message' => "Se eliminaron {$deleted} registro(s).",
            'data' => ['deleted' => $deleted],
        ]);
    }

    public function export(): BinaryFileResponse
    {
        return Excel::download(
            new VehicleCatalogExport($this->catalogService),
            'catalogo_vehiculos_'.now()->format('Y-m-d').'.xlsx',
        );
    }
}
