<?php

namespace App\Services;

use App\Models\CustomerVehicle;
use App\Models\ProductVehicleCompatibility;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\VehicleVin;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VehicleCatalogService
{
    public function configurationPairsQuery(): Builder
    {
        $pivotPairs = DB::table('vehicle_engine_model')
            ->select(['model_id', 'engine_id']);

        $legacyPairs = DB::table('vehicle_engines')
            ->whereNotNull('model_id')
            ->selectRaw('model_id, id as engine_id');

        return DB::query()
            ->fromSub($pivotPairs->union($legacyPairs), 'vehicle_pairs');
    }

    /**
     * @param  array<int>  $modelIds
     * @return array<int, int>
     */
    public function configurationCountsByModel(array $modelIds): array
    {
        if ($modelIds === []) {
            return [];
        }

        return $this->configurationPairsQuery()
            ->whereIn('model_id', $modelIds)
            ->selectRaw('model_id, COUNT(*) as aggregate')
            ->groupBy('model_id')
            ->pluck('aggregate', 'model_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @param  array<int>  $engineIds
     * @return array<int, int>
     */
    public function configurationCountsByEngine(array $engineIds): array
    {
        if ($engineIds === []) {
            return [];
        }

        return $this->configurationPairsQuery()
            ->whereIn('engine_id', $engineIds)
            ->selectRaw('engine_id, COUNT(*) as aggregate')
            ->groupBy('engine_id')
            ->pluck('aggregate', 'engine_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @param  array<int>  $makeIds
     * @return array<int, int>
     */
    public function configurationCountsByMake(array $makeIds): array
    {
        if ($makeIds === []) {
            return [];
        }

        return $this->configurationPairsQuery()
            ->join('vehicle_models', 'vehicle_models.id', '=', 'vehicle_pairs.model_id')
            ->whereIn('vehicle_models.make_id', $makeIds)
            ->selectRaw('vehicle_models.make_id, COUNT(*) as aggregate')
            ->groupBy('vehicle_models.make_id')
            ->pluck('aggregate', 'vehicle_models.make_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @param  array<int>  $makeIds
     * @return array<int, int>
     */
    public function engineCountsByMake(array $makeIds): array
    {
        if ($makeIds === []) {
            return [];
        }

        $directMakes = DB::table('vehicle_engines')
            ->whereNotNull('make_id')
            ->selectRaw('id as engine_id, make_id');
        $legacyModelMakes = DB::table('vehicle_engines')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'vehicle_engines.model_id')
            ->selectRaw('vehicle_engines.id as engine_id, vehicle_models.make_id as make_id');
        $pivotModelMakes = DB::table('vehicle_engine_model')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'vehicle_engine_model.model_id')
            ->selectRaw('vehicle_engine_model.engine_id as engine_id, vehicle_models.make_id as make_id');

        return DB::query()
            ->fromSub($directMakes->union($legacyModelMakes)->union($pivotModelMakes), 'engine_makes')
            ->whereIn('make_id', $makeIds)
            ->selectRaw('make_id, COUNT(DISTINCT engine_id) as aggregate')
            ->groupBy('make_id')
            ->pluck('aggregate', 'make_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    public function makeDeleteBlockReason(VehicleMake $make): ?string
    {
        if ($make->models()->exists()) {
            return 'La marca tiene modelos asociados.';
        }

        if (VehicleEngine::query()->where('make_id', $make->id)->exists()) {
            return 'La marca tiene motores asociados.';
        }

        return null;
    }

    public function modelDeleteBlockReason(VehicleModel $model): ?string
    {
        if ($this->configurationExistsForModel($model->id)) {
            return 'El modelo tiene vehículos configurados.';
        }

        if (VehicleVin::query()->where('model_id', $model->id)->exists()) {
            return 'El modelo tiene VIN asociados.';
        }

        if (CustomerVehicle::query()->where('vehicle_model_id', $model->id)->exists()) {
            return 'El modelo está vinculado a vehículos de clientes.';
        }

        if (ProductVehicleCompatibility::query()->where('model_id', $model->id)->exists()) {
            return 'El modelo está utilizado en compatibilidades de productos.';
        }

        return null;
    }

    public function engineDeleteBlockReason(VehicleEngine $engine): ?string
    {
        if ($this->configurationExistsForEngine($engine->id)) {
            return 'El motor tiene vehículos configurados.';
        }

        if (VehicleVin::query()->where('engine_id', $engine->id)->exists()) {
            return 'El motor tiene VIN asociados.';
        }

        if (CustomerVehicle::query()->where('vehicle_engine_id', $engine->id)->exists()) {
            return 'El motor está vinculado a vehículos de clientes.';
        }

        if (ProductVehicleCompatibility::query()->where('engine_id', $engine->id)->exists()) {
            return 'El motor está utilizado en compatibilidades de productos.';
        }

        return null;
    }

    public function configurationDeleteBlockReason(int $modelId, int $engineId): ?string
    {
        if (CustomerVehicle::query()
            ->where('vehicle_model_id', $modelId)
            ->where('vehicle_engine_id', $engineId)
            ->exists()) {
            return 'La configuración está vinculada a un vehículo de cliente.';
        }

        if (ProductVehicleCompatibility::query()
            ->where('model_id', $modelId)
            ->where('engine_id', $engineId)
            ->exists()) {
            return 'La configuración está utilizada en compatibilidades de productos.';
        }

        return null;
    }

    /**
     * @param  array<int>  $targetModelIds
     */
    public function assertEngineModelChangesAreSafe(VehicleEngine $engine, array $targetModelIds): void
    {
        $currentModelIds = $this->configurationPairsQuery()
            ->where('engine_id', $engine->id)
            ->pluck('model_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach (array_diff($currentModelIds, $targetModelIds) as $modelId) {
            if (VehicleVin::query()->where('model_id', $modelId)->where('engine_id', $engine->id)->exists()) {
                throw ValidationException::withMessages([
                    'model_ids' => ['Elimina primero los VIN de la configuración desde el tab Vehículos.'],
                ]);
            }

            $reason = $this->configurationDeleteBlockReason($modelId, (int) $engine->id);
            if ($reason !== null) {
                throw ValidationException::withMessages([
                    'model_ids' => ["No se puede desvincular el modelo: {$reason}"],
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{vin: string, notes?: string|null}>  $vins
     */
    public function createConfiguration(int $modelId, int $engineId, array $vins): array
    {
        return DB::transaction(function () use ($modelId, $engineId, $vins): array {
            [$model, $engine] = $this->lockAndValidatePair($modelId, $engineId);

            if ($this->configurationExists($modelId, $engineId)) {
                throw ValidationException::withMessages([
                    'engine_id' => ['Esta combinación de modelo y motor ya existe.'],
                ]);
            }

            $engine->models()->syncWithoutDetaching([$model->id]);
            if ($engine->model_id === null) {
                $engine->update(['model_id' => $model->id]);
            }

            $this->syncVins($model->id, $engine->id, $vins);

            return ['model_id' => $model->id, 'engine_id' => $engine->id];
        });
    }

    /**
     * @param  array<int, array{vin: string, notes?: string|null}>  $vins
     */
    public function updateConfiguration(
        int $currentModelId,
        int $currentEngineId,
        int $targetModelId,
        int $targetEngineId,
        array $vins,
    ): array {
        return DB::transaction(function () use ($currentModelId, $currentEngineId, $targetModelId, $targetEngineId, $vins): array {
            $this->assertConfigurationExists($currentModelId, $currentEngineId);
            [$targetModel, $targetEngine] = $this->lockAndValidatePair($targetModelId, $targetEngineId);

            $pairChanged = $currentModelId !== $targetModelId || $currentEngineId !== $targetEngineId;
            if ($pairChanged) {
                $reason = $this->configurationDeleteBlockReason($currentModelId, $currentEngineId);
                if ($reason !== null) {
                    throw ValidationException::withMessages(['configuration' => [$reason]]);
                }

                if ($this->configurationExists($targetModelId, $targetEngineId)) {
                    throw ValidationException::withMessages([
                        'engine_id' => ['La configuración de destino ya existe.'],
                    ]);
                }

                VehicleVin::query()
                    ->where('model_id', $currentModelId)
                    ->where('engine_id', $currentEngineId)
                    ->update([
                        'model_id' => $targetModelId,
                        'engine_id' => $targetEngineId,
                    ]);

                $targetEngine->models()->syncWithoutDetaching([$targetModel->id]);
                $this->detachConfigurationPair($currentModelId, $currentEngineId);
            }

            $this->syncVins($targetModelId, $targetEngineId, $vins);

            return ['model_id' => $targetModelId, 'engine_id' => $targetEngineId];
        });
    }

    public function deleteConfiguration(int $modelId, int $engineId): void
    {
        DB::transaction(function () use ($modelId, $engineId): void {
            $this->assertConfigurationExists($modelId, $engineId);
            $reason = $this->configurationDeleteBlockReason($modelId, $engineId);

            if ($reason !== null) {
                throw ValidationException::withMessages(['configuration' => [$reason]]);
            }

            VehicleVin::query()
                ->where('model_id', $modelId)
                ->where('engine_id', $engineId)
                ->delete();

            $this->detachConfigurationPair($modelId, $engineId);
        });
    }

    public function deleteMake(VehicleMake $make): void
    {
        $reason = $this->makeDeleteBlockReason($make);
        if ($reason !== null) {
            throw ValidationException::withMessages(['make' => [$reason]]);
        }

        $make->delete();
    }

    public function deleteModel(VehicleModel $model): void
    {
        $reason = $this->modelDeleteBlockReason($model);
        if ($reason !== null) {
            throw ValidationException::withMessages(['model' => [$reason]]);
        }

        $model->delete();
    }

    public function deleteEngine(VehicleEngine $engine): void
    {
        $reason = $this->engineDeleteBlockReason($engine);
        if ($reason !== null) {
            throw ValidationException::withMessages(['engine' => [$reason]]);
        }

        $engine->delete();
    }

    /**
     * @param  array<int>  $ids
     * @param  array<int, array{model_id: int, engine_id: int}>  $configurations
     */
    public function bulkDelete(string $entity, array $ids, array $configurations): int
    {
        return DB::transaction(function () use ($entity, $ids, $configurations): int {
            if ($entity === 'vehicles') {
                foreach ($configurations as $configuration) {
                    $this->assertConfigurationExists($configuration['model_id'], $configuration['engine_id']);
                    $reason = $this->configurationDeleteBlockReason($configuration['model_id'], $configuration['engine_id']);
                    if ($reason !== null) {
                        throw ValidationException::withMessages(['selection' => [$reason]]);
                    }
                }

                foreach ($configurations as $configuration) {
                    $this->deleteConfiguration($configuration['model_id'], $configuration['engine_id']);
                }

                return count($configurations);
            }

            $models = match ($entity) {
                'makes' => VehicleMake::query()->whereIn('id', $ids)->lockForUpdate()->get(),
                'models' => VehicleModel::query()->whereIn('id', $ids)->lockForUpdate()->get(),
                'engines' => VehicleEngine::query()->whereIn('id', $ids)->lockForUpdate()->get(),
                default => throw ValidationException::withMessages(['entity' => ['Tipo de catálogo inválido.']]),
            };

            if ($models->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['selection' => ['Uno o más registros ya no existen.']]);
            }

            foreach ($models as $model) {
                $reason = match ($entity) {
                    'makes' => $this->makeDeleteBlockReason($model),
                    'models' => $this->modelDeleteBlockReason($model),
                    'engines' => $this->engineDeleteBlockReason($model),
                };

                if ($reason !== null) {
                    throw ValidationException::withMessages(['selection' => [$reason]]);
                }
            }

            foreach ($models as $model) {
                $model->delete();
            }

            return $models->count();
        });
    }

    private function configurationExists(int $modelId, int $engineId): bool
    {
        return $this->configurationPairsQuery()
            ->where('model_id', $modelId)
            ->where('engine_id', $engineId)
            ->exists();
    }

    private function configurationExistsForModel(int $modelId): bool
    {
        return $this->configurationPairsQuery()->where('model_id', $modelId)->exists();
    }

    private function configurationExistsForEngine(int $engineId): bool
    {
        return $this->configurationPairsQuery()->where('engine_id', $engineId)->exists();
    }

    private function assertConfigurationExists(int $modelId, int $engineId): void
    {
        if (! $this->configurationExists($modelId, $engineId)) {
            throw ValidationException::withMessages(['configuration' => ['La configuración seleccionada no existe.']]);
        }
    }

    /**
     * @return array{0: VehicleModel, 1: VehicleEngine}
     */
    private function lockAndValidatePair(int $modelId, int $engineId): array
    {
        $model = VehicleModel::query()->lockForUpdate()->findOrFail($modelId);
        $engine = VehicleEngine::query()->lockForUpdate()->findOrFail($engineId);
        $engineMakeId = $engine->make_id ?? $engine->model?->make_id;

        if ((int) $engineMakeId !== (int) $model->make_id) {
            throw ValidationException::withMessages([
                'engine_id' => ['El motor y el modelo deben pertenecer a la misma marca.'],
            ]);
        }

        return [$model, $engine];
    }

    /**
     * @param  array<int, array{vin: string, notes?: string|null}>  $vins
     */
    private function syncVins(int $modelId, int $engineId, array $vins): void
    {
        $normalized = collect($vins)
            ->map(fn (array $vin): array => [
                'vin' => mb_strtoupper(trim($vin['vin'])),
                'notes' => isset($vin['notes']) ? trim((string) $vin['notes']) ?: null : null,
            ])
            ->filter(fn (array $vin): bool => $vin['vin'] !== '')
            ->values();

        if ($normalized->pluck('vin')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['vins' => ['No se permiten VIN repetidos.']]);
        }

        $existing = VehicleVin::query()
            ->where('model_id', $modelId)
            ->where('engine_id', $engineId)
            ->get()
            ->keyBy(fn (VehicleVin $vin): string => mb_strtoupper(trim($vin->vin)));

        $desiredValues = $normalized->pluck('vin');
        foreach ($existing as $vinValue => $vin) {
            if ($desiredValues->contains($vinValue)) {
                continue;
            }

            if (CustomerVehicle::query()
                ->where('vehicle_model_id', $modelId)
                ->where('vehicle_engine_id', $engineId)
                ->whereRaw('UPPER(TRIM(vin)) = ?', [$vinValue])
                ->exists()) {
                throw ValidationException::withMessages([
                    'vins' => ["El VIN {$vinValue} está vinculado a un cliente y no puede eliminarse."],
                ]);
            }

            $vin->delete();
        }

        foreach ($normalized as $vinData) {
            VehicleVin::query()->updateOrCreate(
                [
                    'model_id' => $modelId,
                    'engine_id' => $engineId,
                    'vin' => $vinData['vin'],
                ],
                [
                    'chassis_id' => null,
                    'notes' => $vinData['notes'],
                ],
            );
        }
    }

    private function detachConfigurationPair(int $modelId, int $engineId): void
    {
        DB::table('vehicle_engine_model')
            ->where('model_id', $modelId)
            ->where('engine_id', $engineId)
            ->delete();

        $engine = VehicleEngine::query()->lockForUpdate()->find($engineId);
        if ($engine === null || (int) $engine->model_id !== $modelId) {
            return;
        }

        $replacementModelId = DB::table('vehicle_engine_model')
            ->where('engine_id', $engineId)
            ->orderBy('model_id')
            ->value('model_id');

        $engine->update(['model_id' => $replacementModelId ? (int) $replacementModelId : null]);
    }
}
