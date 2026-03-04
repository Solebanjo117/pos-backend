<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleModel;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleModelController extends Controller
{
    /**
     * Display a listing of vehicle models.
     */
    public function index(Request $request): JsonResponse
    {
        $query = VehicleModel::query()
            ->with(['make', 'engines'])
            ->withCount('engines');

        // Filter by make
        if ($request->has('make_id')) {
            $query->where('make_id', $request->input('make_id'));
        }

        // Search
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhereHas('make', function ($makeQuery) use ($search) {
                      $makeQuery->where('name', 'ilike', "%{$search}%");
                  });
            });
        }

        // Filter by year
        if ($request->has('year')) {
            $year = $request->input('year');
            $query->where('year_from', '<=', $year)
                  ->where('year_to', '>=', $year);
        }

        // Pagination
        $perPage = $request->input('per_page', 20);
        $models = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => $models->items(),
            'meta' => [
                'current_page' => $models->currentPage(),
                'last_page' => $models->lastPage(),
                'per_page' => $models->perPage(),
                'total' => $models->total(),
            ],
        ]);
    }

    /**
     * Store a newly created vehicle model.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'make_id' => 'required|exists:vehicle_makes,id',
            'name' => 'required|string|max:100',
            'year_from' => 'required|integer|min:1900|max:' . (date('Y') + 2),
            'year_to' => 'required|integer|min:1900|max:' . (date('Y') + 2) . '|gte:year_from',
        ]);

        $model = VehicleModel::create($validated);

        return response()->json([
            'message' => 'Modelo creado exitosamente',
            'data' => $model->load(['make', 'engines']),
        ], 201);
    }

    /**
     * Display the specified vehicle model.
     */
    public function show(string $id): JsonResponse
    {
        $model = VehicleModel::with(['make', 'engines'])->findOrFail($id);

        return response()->json(['data' => $model]);
    }

    /**
     * Update the specified vehicle model.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $model = VehicleModel::findOrFail($id);

        $validated = $request->validate([
            'make_id' => 'sometimes|required|exists:vehicle_makes,id',
            'name' => 'sometimes|required|string|max:100',
            'year_from' => 'sometimes|required|integer|min:1900|max:' . (date('Y') + 2),
            'year_to' => 'sometimes|required|integer|min:1900|max:' . (date('Y') + 2) . '|gte:year_from',
        ]);

        $model->update($validated);

        return response()->json([
            'message' => 'Modelo actualizado exitosamente',
            'data' => $model->load(['make', 'engines']),
        ]);
    }

    /**
     * Remove the specified vehicle model.
     */
    public function destroy(string $id): JsonResponse
    {
        $model = VehicleModel::findOrFail($id);

        // Check if model has engines
        if ($model->engines()->count() > 0) {
            return response()->json([
                'message' => 'No se puede eliminar el modelo porque tiene motores asociados',
            ], 422);
        }

        $model->delete();

        return response()->json([
            'message' => 'Modelo eliminado exitosamente',
        ]);
    }

    /**
     * Get models by make ID (helper endpoint).
     */
    public function byMake(string $makeId): JsonResponse
    {
        $models = VehicleModel::where('make_id', $makeId)
            ->with('engines')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $models]);
    }
}
