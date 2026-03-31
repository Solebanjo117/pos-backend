<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleEngine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleEngineController extends Controller
{
    /**
     * Display a listing of vehicle engines.
     */
    public function index(Request $request): JsonResponse
    {
        $query = VehicleEngine::query()->with(['model.make']);

        // Filter by model
        if ($request->has('model_id')) {
            $query->where('model_id', $request->input('model_id'));
        }

        // Filter by make (via relationship)
        if ($request->has('make_id')) {
            $query->whereHas('model', function ($q) use ($request) {
                $q->where('make_id', $request->input('make_id'));
            });
        }

        // Search
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('engine_code', 'ilike', "%{$search}%")
                  ->orWhere('displacement', 'ilike', "%{$search}%")
                  ->orWhere('fuel_type', 'ilike', "%{$search}%");
            });
        }

        // Pagination
        $perPage = $request->input('per_page', 50);
        $engines = $query->orderBy('engine_code')->paginate($perPage);

        return response()->json([
            'data' => $engines->items(),
            'meta' => [
                'current_page' => $engines->currentPage(),
                'last_page' => $engines->lastPage(),
                'per_page' => $engines->perPage(),
                'total' => $engines->total(),
            ],
        ]);
    }

    /**
     * Store a newly created vehicle engine.
     * R21: Motors are independent entities (model_id is optional)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model_id' => 'nullable|exists:vehicle_models,id',
            'engine_code' => 'required|string|max:50',
            'displacement' => 'nullable|string|max:20',
            'fuel_type' => 'nullable|string|max:50',
            'aspiration' => 'nullable|string|max:50',
        ]);

        $engine = VehicleEngine::create($validated);

        return response()->json([
            'message' => 'Motor creado exitosamente',
            'data' => $engine->load('model.make'),
        ], 201);
    }

    /**
     * Display the specified vehicle engine.
     */
    public function show(string $id): JsonResponse
    {
        $engine = VehicleEngine::with(['model.make', 'compatibleProducts'])
            ->findOrFail($id);

        return response()->json(['data' => $engine]);
    }

    /**
     * Update the specified vehicle engine.
     * R21: Motors are independent entities (model_id is optional)
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $engine = VehicleEngine::findOrFail($id);

        $validated = $request->validate([
            'model_id' => 'nullable|exists:vehicle_models,id',
            'engine_code' => 'sometimes|required|string|max:50',
            'displacement' => 'nullable|string|max:20',
            'fuel_type' => 'nullable|string|max:50',
            'aspiration' => 'nullable|string|max:50',
        ]);

        $engine->update($validated);

        return response()->json([
            'message' => 'Motor actualizado exitosamente',
            'data' => $engine->load('model.make'),
        ]);
    }

    /**
     * Remove the specified vehicle engine.
     */
    public function destroy(string $id): JsonResponse
    {
        $engine = VehicleEngine::findOrFail($id);

        // Check if engine has products
        if ($engine->compatibility()->count() > 0) {
            return response()->json([
                'message' => 'No se puede eliminar el motor porque tiene productos compatibles asociados',
            ], 422);
        }

        $engine->delete();

        return response()->json([
            'message' => 'Motor eliminado exitosamente',
        ]);
    }

    /**
     * Get engines by model ID (helper endpoint).
     */
    public function byModel(string $modelId): JsonResponse
    {
        $engines = VehicleEngine::where('model_id', $modelId)
            ->orderBy('engine_code')
            ->get();

        return response()->json(['data' => $engines]);
    }
}
