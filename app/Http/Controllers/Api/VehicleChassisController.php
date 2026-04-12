<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleChassis;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleChassisController extends Controller
{
    /**
     * Display a listing of vehicle chassis.
     */
    public function index(Request $request): JsonResponse
    {
        $query = VehicleChassis::query()->with(['model.make']);

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

        // Search by code or name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('code', 'ilike', "%{$search}%")
                  ->orWhere('name', 'ilike', "%{$search}%");
            });
        }

        // Pagination
        $perPage  = $request->input('per_page', 50);
        $chassis  = $query->orderBy('code')->paginate($perPage);

        return response()->json([
            'data' => $chassis->items(),
            'meta' => [
                'current_page' => $chassis->currentPage(),
                'last_page'    => $chassis->lastPage(),
                'per_page'     => $chassis->perPage(),
                'total'        => $chassis->total(),
            ],
        ]);
    }

    /**
     * Store a newly created vehicle chassis.
     * model_id is optional — chassis can be independent (mirrors R21 for engines).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'       => 'required|string|max:50',
            'name'       => 'nullable|string|max:255',
            'model_id'   => 'nullable|exists:vehicle_models,id',
            'year_from'  => 'nullable|string|max:4',
            'year_to'    => 'nullable|string|max:4',
            'notes'      => 'nullable|string',
        ]);

        $chassis = VehicleChassis::create($validated);

        return response()->json([
            'message' => 'Chasis creado exitosamente',
            'data'    => $chassis->load('model.make'),
        ], 201);
    }

    /**
     * Display the specified vehicle chassis.
     */
    public function show(string $id): JsonResponse
    {
        $chassis = VehicleChassis::with(['model.make', 'compatibleProducts'])
            ->findOrFail($id);

        return response()->json(['data' => $chassis]);
    }

    /**
     * Update the specified vehicle chassis.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $chassis = VehicleChassis::findOrFail($id);

        $validated = $request->validate([
            'code'      => 'sometimes|required|string|max:50',
            'name'      => 'nullable|string|max:255',
            'model_id'  => 'nullable|exists:vehicle_models,id',
            'year_from' => 'nullable|string|max:4',
            'year_to'   => 'nullable|string|max:4',
            'notes'     => 'nullable|string',
        ]);

        $chassis->update($validated);

        return response()->json([
            'message' => 'Chasis actualizado exitosamente',
            'data'    => $chassis->load('model.make'),
        ]);
    }

    /**
     * Remove the specified vehicle chassis.
     */
    public function destroy(string $id): JsonResponse
    {
        $chassis = VehicleChassis::findOrFail($id);

        // Check if chassis has products
        if ($chassis->compatibility()->count() > 0) {
            return response()->json([
                'message' => 'No se puede eliminar el chasis porque tiene productos compatibles asociados',
            ], 422);
        }

        $chassis->delete();

        return response()->json([
            'message' => 'Chasis eliminado exitosamente',
        ]);
    }

    /**
     * Get chassis by model ID (helper endpoint for cascading selectors).
     */
    public function byModel(string $modelId): JsonResponse
    {
        $chassis = VehicleChassis::where('model_id', $modelId)
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $chassis]);
    }
}
