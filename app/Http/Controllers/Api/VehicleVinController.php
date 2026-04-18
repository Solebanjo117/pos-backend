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
     * List VINs for a given engine.
     */
    public function index(int $engineId): JsonResponse
    {
        VehicleEngine::query()->whereKey($engineId)->firstOrFail();

        $vins = VehicleVin::where('engine_id', $engineId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $vins]);
    }

    /**
     * Store a VIN for a given engine.
     */
    public function store(Request $request, int $engineId): JsonResponse
    {
        $engine = VehicleEngine::with('models:id')->findOrFail($engineId);

        $validated = $request->validate([
            'vin'   => 'required|string|max:40|unique:vehicle_vins,vin',
            'notes' => 'nullable|string|max:255',
        ]);

        $vin = VehicleVin::create([
            'model_id'  => $this->resolveModelIdFromEngine($engine),
            'engine_id' => $engineId,
            'vin'       => strtoupper($validated['vin']),
            'notes'     => $validated['notes'] ?? null,
        ]);

        return response()->json(['data' => $vin], 201);
    }

    /**
     * Delete a VIN by engine.
     */
    public function destroy(int $engineId, int $vinId): JsonResponse
    {
        VehicleEngine::query()->whereKey($engineId)->firstOrFail();

        $vin = VehicleVin::where('engine_id', $engineId)->findOrFail($vinId);
        $vin->delete();

        return response()->json(['message' => 'VIN eliminado'], 200);
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
            'vin'   => 'required|string|max:40|unique:vehicle_vins,vin',
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

    private function resolveModelIdFromEngine(VehicleEngine $engine): ?int
    {
        if ($engine->model_id !== null) {
            return (int) $engine->model_id;
        }

        $engine->loadMissing('models:id');

        $pivotModelId = $engine->models
            ->pluck('id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->first();

        return $pivotModelId !== null ? (int) $pivotModelId : null;
    }
}
