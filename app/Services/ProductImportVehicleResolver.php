<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVehicleCompatibility;
use App\Models\VehicleChassis;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\VehicleVin;
use Illuminate\Support\Str;

class ProductImportVehicleResolver
{
    public function hasVehiclePayload(array $row): bool
    {
        return $this->rawVehicleText($row) !== null
            || $this->clean($row['make'] ?? null) !== null
            || $this->clean($row['model'] ?? null) !== null
            || $this->clean($row['chasis'] ?? null) !== null
            || $this->clean($row['engine'] ?? null) !== null
            || $this->clean($row['vin'] ?? null) !== null;
    }

    /**
     * @return array<int, string>
     */
    public function validateRow(array $row): array
    {
        if (! $this->hasVehiclePayload($row)) {
            return [];
        }

        $errors = [];
        $hasStructuredPayload = $this->clean($row['make'] ?? null) !== null
            || $this->clean($row['model'] ?? null) !== null
            || $this->clean($row['chasis'] ?? null) !== null
            || $this->clean($row['engine'] ?? null) !== null
            || $this->clean($row['vin'] ?? null) !== null;

        if ($hasStructuredPayload && ($this->clean($row['make'] ?? null) === null || $this->clean($row['model'] ?? null) === null)) {
            $errors[] = 'Para relacionar carro por columnas separadas se requiere MAKE y MODEL, o usar la columna CARRO como texto completo.';
        }

        if ($this->clean($row['vin'] ?? null) !== null && $this->clean($row['engine'] ?? null) === null) {
            $errors[] = 'Para importar VIN se requiere ENGINE.';
        }

        foreach ($this->splitValues($row['vin'] ?? null) as $vin) {
            if (mb_strlen($vin) > 40) {
                $errors[] = "El VIN '{$vin}' no puede tener mas de 40 caracteres.";
            }
        }

        return $errors;
    }

    /**
     * @return array<int, ProductVehicleCompatibility>
     */
    public function sync(Product $product, array $row): array
    {
        if (! $this->hasVehiclePayload($row)) {
            return [];
        }

        $structured = $this->structuredCompatibilityPayload($row);

        if ($structured !== null) {
            return [$this->firstOrCreateCompatibility($product, $structured)];
        }

        $rawText = $this->rawVehicleText($row) ?? $this->composedVehicleText($row);
        if ($rawText === null) {
            return [];
        }

        return [$this->firstOrCreateRawCompatibility($product, $rawText)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function structuredCompatibilityPayload(array $row): ?array
    {
        $makeName = $this->clean($row['make'] ?? null);
        $modelName = $this->clean($row['model'] ?? null);

        if ($makeName === null || $modelName === null) {
            return null;
        }

        [$yearFrom, $yearTo] = $this->parseYearRangeFromRow($row);
        $make = VehicleMake::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($makeName)])
            ->first();

        if (! $make instanceof VehicleMake && $yearFrom !== null) {
            $make = VehicleMake::query()->create(['name' => mb_strtoupper($makeName)]);
        }

        if (! $make instanceof VehicleMake) {
            return null;
        }

        $modelQuery = VehicleModel::query()
            ->where('make_id', $make->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($modelName)]);

        if ($yearFrom !== null) {
            $modelQuery->where('year_from', $yearFrom)->where('year_to', $yearTo ?? $yearFrom);
        }

        $model = $modelQuery->first();
        if (! $model instanceof VehicleModel && $yearFrom !== null) {
            $model = VehicleModel::query()->create([
                'make_id' => $make->id,
                'name' => mb_strtoupper($modelName),
                'year_from' => $yearFrom,
                'year_to' => $yearTo ?? $yearFrom,
                'generation' => $this->clean($row['chasis'] ?? null),
            ]);
        }

        if (! $model instanceof VehicleModel) {
            return null;
        }

        $chassis = $this->resolveChassis($model, $row, $yearFrom, $yearTo);
        $engine = $this->resolveEngine($make, $model, $row);
        $this->syncVins($model, $chassis, $engine, $row);

        if ($engine instanceof VehicleEngine) {
            return [
                'scope_type' => 'engine',
                'make_id' => $make->id,
                'model_id' => $model->id,
                'engine_id' => $engine->id,
                'chassis_id' => $chassis?->id,
            ];
        }

        if ($chassis instanceof VehicleChassis) {
            return [
                'scope_type' => 'chassis',
                'make_id' => $make->id,
                'model_id' => $model->id,
                'engine_id' => null,
                'chassis_id' => $chassis->id,
            ];
        }

        return [
            'scope_type' => 'model',
            'make_id' => $make->id,
            'model_id' => $model->id,
            'engine_id' => null,
            'chassis_id' => null,
        ];
    }

    private function resolveChassis(VehicleModel $model, array $row, ?int $yearFrom, ?int $yearTo): ?VehicleChassis
    {
        $chassisCode = $this->clean($row['chasis'] ?? null);
        if ($chassisCode === null) {
            return null;
        }

        $chassis = VehicleChassis::query()
            ->where(function ($query) use ($model): void {
                $query->where('model_id', $model->id)
                    ->orWhereHas('models', fn ($modelsQuery) => $modelsQuery->where('vehicle_models.id', $model->id));
            })
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($chassisCode)])
            ->first();

        if (! $chassis instanceof VehicleChassis) {
            $chassis = VehicleChassis::query()->create([
                'code' => mb_strtoupper($chassisCode),
                'name' => mb_strtoupper($chassisCode),
                'model_id' => $model->id,
                'year_from' => $yearFrom ?? $model->year_from,
                'year_to' => $yearTo ?? $model->year_to,
            ]);
        }

        $model->chassis()->syncWithoutDetaching([$chassis->id]);

        return $chassis;
    }

    private function resolveEngine(VehicleMake $make, VehicleModel $model, array $row): ?VehicleEngine
    {
        $engineCode = $this->normalizeEngineCode($row['engine'] ?? null);
        if ($engineCode === null) {
            return null;
        }

        $engine = VehicleEngine::query()
            ->where(function ($query) use ($model): void {
                $query->where('model_id', $model->id)
                    ->orWhereHas('models', fn ($modelsQuery) => $modelsQuery->where('vehicle_models.id', $model->id));
            })
            ->whereRaw('LOWER(TRIM(engine_code)) = ?', [mb_strtolower($engineCode)])
            ->first();

        if (! $engine instanceof VehicleEngine) {
            $engine = VehicleEngine::query()->create([
                'make_id' => $make->id,
                'model_id' => $model->id,
                'engine_code' => $engineCode,
            ]);
        }

        $engine->models()->syncWithoutDetaching([$model->id]);

        return $engine;
    }

    private function syncVins(VehicleModel $model, ?VehicleChassis $chassis, ?VehicleEngine $engine, array $row): void
    {
        if (! $engine instanceof VehicleEngine) {
            return;
        }

        foreach ($this->splitValues($row['vin'] ?? null) as $vin) {
            VehicleVin::query()->firstOrCreate([
                'model_id' => $model->id,
                'chassis_id' => $chassis?->id,
                'engine_id' => $engine->id,
                'vin' => mb_strtoupper($vin),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function firstOrCreateCompatibility(Product $product, array $payload): ProductVehicleCompatibility
    {
        $query = ProductVehicleCompatibility::query()
            ->where('product_id', $product->id)
            ->where('scope_type', $payload['scope_type'])
            ->where('make_id', $payload['make_id'])
            ->where('model_id', $payload['model_id']);

        foreach (['engine_id', 'chassis_id'] as $field) {
            $value = $payload[$field] ?? null;
            $value === null ? $query->whereNull($field) : $query->where($field, $value);
        }

        $compatibility = $query->first();

        return $compatibility instanceof ProductVehicleCompatibility
            ? $compatibility
            : ProductVehicleCompatibility::query()->create(array_merge($payload, [
                'product_id' => $product->id,
            ]));
    }

    private function firstOrCreateRawCompatibility(Product $product, string $rawText): ProductVehicleCompatibility
    {
        $rawKey = $this->rawVehicleKey($rawText);
        $compatibility = ProductVehicleCompatibility::query()
            ->where('product_id', $product->id)
            ->where('scope_type', 'raw')
            ->where('raw_vehicle_key', $rawKey)
            ->first();

        return $compatibility instanceof ProductVehicleCompatibility
            ? $compatibility
            : ProductVehicleCompatibility::query()->create([
                'product_id' => $product->id,
                'scope_type' => 'raw',
                'raw_vehicle_key' => $rawKey,
                'raw_vehicle_text' => $rawText,
                'notes' => 'Importado desde columna CARRO',
            ]);
    }

    private function rawVehicleText(array $row): ?string
    {
        return $this->clean($row['carro'] ?? null);
    }

    private function composedVehicleText(array $row): ?string
    {
        $parts = array_filter([
            $this->clean($row['make'] ?? null),
            $this->clean($row['model'] ?? null),
            $this->clean($row['chasis'] ?? null),
            $this->clean($row['engine'] ?? null),
            $this->clean($row['vin'] ?? null),
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    private function rawVehicleKey(string $rawText): string
    {
        return mb_strtolower(trim(Str::ascii(preg_replace('/\s+/u', ' ', $rawText) ?? $rawText)));
    }

    private function parseYearRangeFromRow(array $row): array
    {
        $yearFrom = $this->parseInteger($row['year_from'] ?? null);
        $yearTo = $this->parseInteger($row['year_to'] ?? null);

        if ($yearFrom !== null) {
            return [$yearFrom, $yearTo ?? $yearFrom];
        }

        $year = $this->clean($row['year'] ?? null);
        if ($year === null) {
            return [null, null];
        }

        if (preg_match('/^(\d{4})\s*(?:-|\x{2013})\s*(\d{4})$/u', $year, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        if (preg_match('/^(\d{4})$/', $year, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[1]];
        }

        return [null, null];
    }

    private function normalizeEngineCode(mixed $engineCode): ?string
    {
        $raw = $this->clean($engineCode);

        return $raw === null ? null : (string) preg_replace('/\s+/u', ' ', mb_strtoupper($raw));
    }

    /**
     * @return array<int, string>
     */
    private function splitValues(mixed $value): array
    {
        $raw = $this->clean($value);
        if ($raw === null) {
            return [];
        }

        return collect(preg_split('/[;,|]+/', $raw) ?: [])
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->values()
            ->all();
    }

    private function parseInteger(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function clean(mixed $value): ?string
    {
        $clean = trim((string) ($value ?? ''));

        return $clean === '' ? null : $clean;
    }
}
