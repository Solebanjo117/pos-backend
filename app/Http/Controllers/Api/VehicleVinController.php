<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleEngine;
use App\Models\VehicleVin;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleVinController extends Controller
{
    /**
     * Legacy endpoint: List VINs by engine.
     * Kept temporarily for backward compatibility.
     */
    public function index(int $engineId): JsonResponse
    {
        $modelId = $this->resolveModelIdFromEngine($engineId);
        if (! $modelId) {
            return response()->json(['data' => []]);
        }

        return $this->indexByModel($modelId);
    }

    /**
     * Legacy endpoint: Store VIN by engine.
     * Kept temporarily for backward compatibility.
     */
    public function store(Request $request, int $engineId): JsonResponse
    {
        $modelId = $this->resolveModelIdFromEngine($engineId);
        if (! $modelId) {
            return response()->json([
                'message' => 'El motor no tiene un modelo asociado',
            ], 422);
        }

        $validated = $request->validate([
            'vin'   => 'required|string|max:17|unique:vehicle_vins,vin',
            'notes' => 'nullable|string|max:255',
        ]);

        $vin = VehicleVin::create([
            'model_id'  => $modelId,
            'engine_id' => $engineId,
            'vin'       => strtoupper($validated['vin']),
            'notes'     => $validated['notes'] ?? null,
        ]);

        return response()->json(['data' => $vin], 201);
    }

    /**
     * Legacy endpoint: Delete VIN by engine.
     * Kept temporarily for backward compatibility.
     */
    public function destroy(int $engineId, int $vinId): JsonResponse
    {
        $modelId = $this->resolveModelIdFromEngine($engineId);
        if (! $modelId) {
            return response()->json([
                'message' => 'El motor no tiene un modelo asociado',
            ], 422);
        }

        return $this->destroyByModel($modelId, $vinId);
    }

    /** List VINs for a given model */
    public function indexByModel(int $modelId): JsonResponse
    {
        $vins = VehicleVin::where('model_id', $modelId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $vins]);
    }

    /** Store a VIN for a model */
    public function storeByModel(Request $request, int $modelId): JsonResponse
    {
        $validated = $request->validate([
            'vin'   => 'required|string|max:17|unique:vehicle_vins,vin',
            'notes' => 'nullable|string|max:255',
        ]);

        $vin = VehicleVin::create([
            'model_id'  => $modelId,
            'engine_id' => null,
            'vin'       => strtoupper($validated['vin']),
            'notes'     => $validated['notes'] ?? null,
        ]);

        return response()->json(['data' => $vin], 201);
    }

    /** Delete a VIN by model */
    public function destroyByModel(int $modelId, int $vinId): JsonResponse
    {
        $vin = VehicleVin::where('model_id', $modelId)->findOrFail($vinId);
        $vin->delete();

        return response()->json(['message' => 'VIN eliminado'], 200);
    }

    private function resolveModelIdFromEngine(int $engineId): ?int
    {
        return VehicleEngine::whereKey($engineId)->value('model_id');
    }
}
