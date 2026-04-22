<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleMake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleMakeController extends Controller
{
    /**
     * Display a listing of vehicle makes.
     */
    public function index(Request $request): JsonResponse
    {
        $query = VehicleMake::query()->withCount('models');

        // Search
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        // Pagination
        $perPage = $request->input('per_page', 20);
        $makes = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => $makes->items(),
            'meta' => [
                'current_page' => $makes->currentPage(),
                'last_page' => $makes->lastPage(),
                'per_page' => $makes->perPage(),
                'total' => $makes->total(),
            ],
        ]);
    }

    /**
     * Store a newly created vehicle make.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:vehicle_makes,name',
        ]);

        $make = VehicleMake::create($validated);

        return response()->json([
            'message' => 'Marca creada exitosamente',
            'data' => $make->load('models'),
        ], 201);
    }

    /**
     * Display the specified vehicle make.
     */
    public function show(string $id): JsonResponse
    {
        $make = VehicleMake::with(['models' => function ($query) {
            $query->with('engines')->orderBy('name');
        }])->findOrFail($id);

        return response()->json(['data' => $make]);
    }

    /**
     * Update the specified vehicle make.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $make = VehicleMake::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:vehicle_makes,name,'.$id,
        ]);

        $make->update($validated);

        return response()->json([
            'message' => 'Marca actualizada exitosamente',
            'data' => $make->load('models'),
        ]);
    }

    /**
     * Remove the specified vehicle make.
     */
    public function destroy(string $id): JsonResponse
    {
        $make = VehicleMake::findOrFail($id);

        // Check if make has models
        if ($make->models()->count() > 0) {
            return response()->json([
                'message' => 'No se puede eliminar la marca porque tiene modelos asociados',
            ], 422);
        }

        $make->delete();

        return response()->json([
            'message' => 'Marca eliminada exitosamente',
        ]);
    }

    /**
     * Get all vehicle makes (for dropdowns).
     */
    public function all(): JsonResponse
    {
        $makes = VehicleMake::orderBy('name')
            ->select('id', 'name')
            ->get();

        return response()->json(['data' => $makes]);
    }
}
