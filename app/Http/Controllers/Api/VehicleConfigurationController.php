<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVehicleConfigurationRequest;
use App\Http\Requests\UpdateVehicleConfigurationRequest;
use App\Models\CustomerVehicle;
use App\Models\ProductVehicleCompatibility;
use App\Models\VehicleVin;
use App\Services\VehicleCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleConfigurationController extends Controller
{
    public function __construct(private VehicleCatalogService $catalogService) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->catalogService->configurationPairsQuery()
            ->join('vehicle_models', 'vehicle_models.id', '=', 'vehicle_pairs.model_id')
            ->join('vehicle_makes', 'vehicle_makes.id', '=', 'vehicle_models.make_id')
            ->join('vehicle_engines', 'vehicle_engines.id', '=', 'vehicle_pairs.engine_id')
            ->select([
                'vehicle_pairs.model_id',
                'vehicle_pairs.engine_id',
                'vehicle_makes.id as make_id',
                'vehicle_makes.name as make_name',
                'vehicle_models.name as model_name',
                'vehicle_models.year_from',
                'vehicle_models.year_to',
                'vehicle_models.generation',
                'vehicle_engines.engine_code',
                'vehicle_engines.displacement',
                'vehicle_engines.fuel_type',
                'vehicle_engines.aspiration',
            ]);

        $search = mb_strtolower(trim((string) $request->input('search', '')));
        foreach (preg_split('/\s+/u', $search) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            $like = "%{$token}%";
            $query->where(function ($scope) use ($like): void {
                $scope->whereRaw('LOWER(vehicle_makes.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(vehicle_models.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(vehicle_models.generation, ?)) LIKE ?', ['', $like])
                    ->orWhereRaw('LOWER(vehicle_engines.engine_code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(vehicle_engines.displacement, ?)) LIKE ?', ['', $like])
                    ->orWhereExists(function ($vinQuery) use ($like): void {
                        $vinQuery->selectRaw('1')
                            ->from('vehicle_vins')
                            ->whereColumn('vehicle_vins.model_id', 'vehicle_pairs.model_id')
                            ->whereColumn('vehicle_vins.engine_id', 'vehicle_pairs.engine_id')
                            ->whereRaw('LOWER(vehicle_vins.vin) LIKE ?', [$like]);
                    });
            });
        }

        $sorts = [
            'make' => 'vehicle_makes.name',
            'model' => 'vehicle_models.name',
            'years' => 'vehicle_models.year_from',
            'engine' => 'vehicle_engines.engine_code',
        ];
        $sort = $sorts[(string) $request->input('sort')] ?? 'vehicle_makes.name';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $paginator = $query
            ->orderBy($sort, $direction)
            ->orderBy('vehicle_models.name')
            ->orderBy('vehicle_engines.engine_code')
            ->paginate($perPage);

        $rows = collect($paginator->items());
        $modelIds = $rows->pluck('model_id')->unique()->values();
        $engineIds = $rows->pluck('engine_id')->unique()->values();
        $vins = VehicleVin::query()
            ->whereIn('model_id', $modelIds)
            ->whereIn('engine_id', $engineIds)
            ->orderBy('vin')
            ->get()
            ->groupBy(fn (VehicleVin $vin): string => "{$vin->model_id}:{$vin->engine_id}");
        $customerPairs = CustomerVehicle::query()
            ->whereIn('vehicle_model_id', $modelIds)
            ->whereIn('vehicle_engine_id', $engineIds)
            ->get(['vehicle_model_id', 'vehicle_engine_id', 'vin'])
            ->mapWithKeys(fn (CustomerVehicle $vehicle): array => ["{$vehicle->vehicle_model_id}:{$vehicle->vehicle_engine_id}" => true]);
        $customerVins = CustomerVehicle::query()
            ->whereIn('vehicle_model_id', $modelIds)
            ->whereIn('vehicle_engine_id', $engineIds)
            ->whereNotNull('vin')
            ->get(['vehicle_model_id', 'vehicle_engine_id', 'vin'])
            ->mapWithKeys(fn (CustomerVehicle $vehicle): array => [
                "{$vehicle->vehicle_model_id}:{$vehicle->vehicle_engine_id}:".mb_strtoupper(trim((string) $vehicle->vin)) => true,
            ]);
        $compatibilityPairs = ProductVehicleCompatibility::query()
            ->whereIn('model_id', $modelIds)
            ->whereIn('engine_id', $engineIds)
            ->get(['model_id', 'engine_id'])
            ->mapWithKeys(fn (ProductVehicleCompatibility $compatibility): array => ["{$compatibility->model_id}:{$compatibility->engine_id}" => true]);
        $canManage = in_array($request->user()?->role, ['admin', 'manager'], true);

        $data = $rows->map(function ($row) use ($vins, $customerPairs, $customerVins, $compatibilityPairs, $canManage): array {
            $key = "{$row->model_id}:{$row->engine_id}";
            $blockReason = $customerPairs->has($key)
                ? 'La configuración está vinculada a un vehículo de cliente.'
                : ($compatibilityPairs->has($key)
                    ? 'La configuración está utilizada en compatibilidades de productos.'
                    : null);
            $pairVins = $vins->get($key, collect())
                ->map(function (VehicleVin $vin) use ($customerVins, $key): array {
                    return [
                        'id' => $vin->id,
                        'vin' => $vin->vin,
                        'notes' => $vin->notes,
                        'is_customer_linked' => $customerVins->has($key.':'.mb_strtoupper(trim($vin->vin))),
                    ];
                })
                ->values();

            return [
                'key' => $key,
                'make' => ['id' => (int) $row->make_id, 'name' => $row->make_name],
                'model' => [
                    'id' => (int) $row->model_id,
                    'name' => $row->model_name,
                    'year_from' => (int) $row->year_from,
                    'year_to' => (int) $row->year_to,
                    'generation' => $row->generation,
                ],
                'engine' => [
                    'id' => (int) $row->engine_id,
                    'engine_code' => $row->engine_code,
                    'displacement' => $row->displacement,
                    'fuel_type' => $row->fuel_type,
                    'aspiration' => $row->aspiration,
                ],
                'vins' => $pairVins,
                'vins_count' => $pairVins->count(),
                'can_edit' => $canManage,
                'can_delete' => $canManage && $blockReason === null,
                'delete_block_reason' => $blockReason,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreVehicleConfigurationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $configuration = $this->catalogService->createConfiguration(
            $validated['model_id'],
            $validated['engine_id'],
            $validated['vins'] ?? [],
        );

        return response()->json([
            'message' => 'Vehículo creado exitosamente.',
            'data' => $configuration,
        ], 201);
    }

    public function update(
        UpdateVehicleConfigurationRequest $request,
        int $model,
        int $engine,
    ): JsonResponse {
        $validated = $request->validated();
        $configuration = $this->catalogService->updateConfiguration(
            $model,
            $engine,
            $validated['model_id'],
            $validated['engine_id'],
            $validated['vins'] ?? [],
        );

        return response()->json([
            'message' => 'Vehículo actualizado exitosamente.',
            'data' => $configuration,
        ]);
    }

    public function destroy(int $model, int $engine): JsonResponse
    {
        $this->catalogService->deleteConfiguration($model, $engine);

        return response()->json(['message' => 'Vehículo eliminado exitosamente.']);
    }
}
