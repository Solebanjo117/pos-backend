<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\VehicleChassis;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Support\Facades\DB;

class ProductImportReferenceBatchService
{
    public function createReferences(array $categories, array $brands): array
    {
        $categoryResult = $this->emptyReferenceResult();
        $brandResult = $this->emptyReferenceResult();

        DB::transaction(function () use ($categories, $brands, &$categoryResult, &$brandResult): void {
            $categoryResult = $this->createCategories($categories);
            $brandResult = $this->createBrands($brands);
        });

        return [
            'categories' => $categoryResult,
            'brands' => $brandResult,
            'summary' => $this->summary([$categoryResult, $brandResult]),
        ];
    }

    public function createVehicleCandidates(array $candidates): array
    {
        $results = [];
        $summary = [
            'created_count' => 0,
            'existing_count' => 0,
            'skipped_count' => 0,
            'error_count' => 0,
        ];

        foreach ($candidates as $index => $candidate) {
            $candidateResult = $this->createVehicleCandidate($candidate, $index);
            $results[] = $candidateResult;

            foreach (['created_count', 'existing_count', 'skipped_count', 'error_count'] as $key) {
                $summary[$key] += (int) ($candidateResult['summary'][$key] ?? 0);
            }
        }

        return [
            'candidates' => $results,
            'summary' => $summary,
        ];
    }

    private function createCategories(array $names): array
    {
        $result = $this->emptyReferenceResult();
        $seen = [];

        foreach ($names as $name) {
            $normalized = $this->normalizedName($name);
            if ($normalized === '') {
                $result['skipped'][] = ['name' => $name, 'message' => 'Referencia vacia omitida.'];

                continue;
            }

            if (isset($seen[$normalized])) {
                $result['skipped'][] = ['name' => trim((string) $name), 'message' => 'Referencia duplicada en el lote.'];

                continue;
            }
            $seen[$normalized] = true;

            if (mb_strlen(trim((string) $name)) > 100) {
                $result['errors'][] = ['name' => trim((string) $name), 'message' => 'La categoria no puede superar 100 caracteres.'];

                continue;
            }

            $category = $this->categoryByName((string) $name);
            if ($category instanceof Category) {
                $result['existing'][] = $this->categoryPayload($category);

                continue;
            }

            $result['created'][] = $this->categoryPayload(Category::query()->create([
                'name' => trim((string) $name),
            ]));
        }

        return $result;
    }

    private function createBrands(array $names): array
    {
        $result = $this->emptyReferenceResult();
        $seen = [];

        foreach ($names as $name) {
            $normalized = $this->normalizedName($name);
            if ($normalized === '') {
                $result['skipped'][] = ['name' => $name, 'message' => 'Referencia vacia omitida.'];

                continue;
            }

            if (isset($seen[$normalized])) {
                $result['skipped'][] = ['name' => trim((string) $name), 'message' => 'Referencia duplicada en el lote.'];

                continue;
            }
            $seen[$normalized] = true;

            $brand = $this->brandByName((string) $name);
            if ($brand instanceof Brand) {
                $result['existing'][] = $this->brandPayload($brand);

                continue;
            }

            $result['created'][] = $this->brandPayload(Brand::query()->create([
                'name' => trim((string) $name),
                'status' => 'active',
            ]));
        }

        return $result;
    }

    private function createVehicleCandidate(array $candidate, int $index): array
    {
        try {
            return DB::transaction(function () use ($candidate, $index): array {
                $summary = [
                    'created_count' => 0,
                    'existing_count' => 0,
                    'skipped_count' => 0,
                    'error_count' => 0,
                ];

                $makeName = $this->trimmedValue($candidate['make'] ?? null);
                $modelName = $this->trimmedValue($candidate['model'] ?? null);

                if ($makeName === null && empty($candidate['make_id'])) {
                    return $this->vehicleCandidateError($candidate, $index, 'El candidato debe incluir marca del vehiculo.');
                }

                if ($modelName === null && empty($candidate['model_id'])) {
                    return $this->vehicleCandidateError($candidate, $index, 'El candidato debe incluir modelo del vehiculo.');
                }

                [$make, $makeWasCreated] = $this->resolveVehicleMake($candidate, $makeName);
                $summary[$makeWasCreated ? 'created_count' : 'existing_count']++;

                [$model, $modelWasCreated] = $this->resolveVehicleModel($candidate, $make, $modelName);
                $summary[$modelWasCreated ? 'created_count' : 'existing_count']++;

                [$chassis, $chassisWasCreated] = $this->resolveVehicleChassis($candidate, $model);
                if ($chassis instanceof VehicleChassis) {
                    $summary[$chassisWasCreated ? 'created_count' : 'existing_count']++;
                } else {
                    $summary['skipped_count']++;
                }

                [$engine, $engineWasCreated] = $this->resolveVehicleEngine($candidate, $make, $model);
                if ($engine instanceof VehicleEngine) {
                    $summary[$engineWasCreated ? 'created_count' : 'existing_count']++;
                } else {
                    $summary['skipped_count']++;
                }

                return [
                    'index' => $index,
                    'status' => $summary['created_count'] > 0 ? 'created' : 'existing',
                    'input' => $candidate,
                    'make' => $this->vehicleMakePayload($make),
                    'model' => $this->vehicleModelPayload($model),
                    'chassis' => $chassis instanceof VehicleChassis ? $this->vehicleChassisPayload($chassis) : null,
                    'engine' => $engine instanceof VehicleEngine ? $this->vehicleEnginePayload($engine) : null,
                    'summary' => $summary,
                ];
            });
        } catch (\Throwable $e) {
            return $this->vehicleCandidateError($candidate, $index, $e->getMessage());
        }
    }

    private function resolveVehicleMake(array $candidate, ?string $makeName): array
    {
        if (! empty($candidate['make_id'])) {
            return [VehicleMake::query()->findOrFail((int) $candidate['make_id']), false];
        }

        $make = VehicleMake::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $makeName)])
            ->first();

        if ($make instanceof VehicleMake) {
            return [$make, false];
        }

        return [VehicleMake::query()->create(['name' => (string) $makeName]), true];
    }

    private function resolveVehicleModel(array $candidate, VehicleMake $make, ?string $modelName): array
    {
        if (! empty($candidate['model_id'])) {
            return [VehicleModel::query()->findOrFail((int) $candidate['model_id']), false];
        }

        $model = VehicleModel::query()
            ->where('make_id', $make->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $modelName)])
            ->first();

        if ($model instanceof VehicleModel) {
            return [$model, false];
        }

        $yearTo = (int) date('Y') + 2;

        return [VehicleModel::query()->create([
            'make_id' => $make->id,
            'name' => (string) $modelName,
            'year_from' => 1900,
            'year_to' => $yearTo,
            'generation' => $this->trimmedValue($candidate['chassis'] ?? null),
        ]), true];
    }

    private function resolveVehicleChassis(array $candidate, VehicleModel $model): array
    {
        $chassisCode = $this->trimmedValue($candidate['chassis'] ?? null);
        if ($chassisCode === null) {
            return [null, false];
        }

        $chassis = VehicleChassis::query()
            ->where(function ($query) use ($model): void {
                $query->where('model_id', $model->id)
                    ->orWhereHas('models', fn ($modelsQuery) => $modelsQuery->where('vehicle_models.id', $model->id));
            })
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($chassisCode)])
            ->first();

        if ($chassis instanceof VehicleChassis) {
            $chassis->models()->syncWithoutDetaching([$model->id]);

            return [$chassis, false];
        }

        $chassis = VehicleChassis::query()->create([
            'code' => mb_strtoupper($chassisCode),
            'name' => mb_strtoupper($chassisCode),
            'model_id' => $model->id,
            'year_from' => $model->year_from,
            'year_to' => $model->year_to,
        ]);
        $chassis->models()->syncWithoutDetaching([$model->id]);

        return [$chassis, true];
    }

    private function resolveVehicleEngine(array $candidate, VehicleMake $make, VehicleModel $model): array
    {
        $engineCode = $this->trimmedValue($candidate['engine'] ?? null);
        if ($engineCode === null) {
            return [null, false];
        }

        $engine = VehicleEngine::query()
            ->where(function ($query) use ($model): void {
                $query->where('model_id', $model->id)
                    ->orWhereHas('models', fn ($modelsQuery) => $modelsQuery->where('vehicle_models.id', $model->id));
            })
            ->whereRaw('LOWER(engine_code) = ?', [mb_strtolower($engineCode)])
            ->first();

        if ($engine instanceof VehicleEngine) {
            $engine->models()->syncWithoutDetaching([$model->id]);

            return [$engine, false];
        }

        $engine = VehicleEngine::query()->create([
            'make_id' => $make->id,
            'model_id' => $model->id,
            'engine_code' => mb_strtoupper($engineCode),
        ]);
        $engine->models()->syncWithoutDetaching([$model->id]);

        return [$engine, true];
    }

    private function emptyReferenceResult(): array
    {
        return [
            'created' => [],
            'existing' => [],
            'skipped' => [],
            'errors' => [],
        ];
    }

    private function summary(array $results): array
    {
        return [
            'created_count' => collect($results)->sum(fn (array $result): int => count($result['created'])),
            'existing_count' => collect($results)->sum(fn (array $result): int => count($result['existing'])),
            'skipped_count' => collect($results)->sum(fn (array $result): int => count($result['skipped'])),
            'error_count' => collect($results)->sum(fn (array $result): int => count($result['errors'])),
        ];
    }

    private function categoryByName(string $name): ?Category
    {
        return Category::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->first();
    }

    private function brandByName(string $name): ?Brand
    {
        return Brand::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->first();
    }

    private function normalizedName(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function trimmedValue(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function categoryPayload(Category $category): array
    {
        return [
            'id' => (int) $category->id,
            'name' => $category->name,
        ];
    }

    private function brandPayload(Brand $brand): array
    {
        return [
            'id' => (int) $brand->id,
            'name' => $brand->name,
            'status' => $brand->status,
        ];
    }

    private function vehicleCandidateError(array $candidate, int $index, string $message): array
    {
        return [
            'index' => $index,
            'status' => 'error',
            'input' => $candidate,
            'message' => $message,
            'summary' => [
                'created_count' => 0,
                'existing_count' => 0,
                'skipped_count' => 0,
                'error_count' => 1,
            ],
        ];
    }

    private function vehicleMakePayload(VehicleMake $make): array
    {
        return [
            'id' => (int) $make->id,
            'name' => $make->name,
        ];
    }

    private function vehicleModelPayload(VehicleModel $model): array
    {
        return [
            'id' => (int) $model->id,
            'name' => $model->name,
            'make_id' => (int) $model->make_id,
            'year_from' => $model->year_from,
            'year_to' => $model->year_to,
            'generation' => $model->generation,
        ];
    }

    private function vehicleChassisPayload(VehicleChassis $chassis): array
    {
        return [
            'id' => (int) $chassis->id,
            'code' => $chassis->code,
            'name' => $chassis->name,
            'model_id' => $chassis->model_id,
        ];
    }

    private function vehicleEnginePayload(VehicleEngine $engine): array
    {
        return [
            'id' => (int) $engine->id,
            'engine_code' => $engine->engine_code,
            'make_id' => $engine->make_id,
            'model_id' => $engine->model_id,
        ];
    }
}
