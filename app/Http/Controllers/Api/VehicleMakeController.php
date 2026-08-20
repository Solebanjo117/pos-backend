<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleMake;
use App\Services\VehicleCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleMakeController extends Controller
{
    public function __construct(private VehicleCatalogService $catalogService) {}

    /**
     * Display a listing of vehicle makes.
     */
    public function index(Request $request): JsonResponse
    {
        $query = VehicleMake::query()->withCount('models');

        // Search
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }

        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $sort = $request->input('sort') === 'name' ? 'name' : 'name';
        $perPage = min(max((int) $request->input('per_page', 20), 1), 500);
        $makes = $query->orderBy($sort, $direction)->paginate($perPage);
        $items = collect($makes->items());
        $makeIds = $items->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $engineCounts = $this->catalogService->engineCountsByMake($makeIds);
        $vehicleCounts = $this->catalogService->configurationCountsByMake($makeIds);
        $canManage = in_array($request->user()?->role, ['admin', 'manager'], true);
        $data = $items->map(function (VehicleMake $make) use ($engineCounts, $vehicleCounts, $canManage): VehicleMake {
            $blockReason = $this->catalogService->makeDeleteBlockReason($make);
            $make->setAttribute('engines_count', $engineCounts[$make->id] ?? 0);
            $make->setAttribute('vehicles_count', $vehicleCounts[$make->id] ?? 0);
            $make->setAttribute('can_edit', $canManage);
            $make->setAttribute('can_delete', $canManage && $blockReason === null);
            $make->setAttribute('delete_block_reason', $blockReason);

            return $make;
        })->values();

        return response()->json([
            'data' => $data,
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

        $this->catalogService->deleteMake($make);

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
