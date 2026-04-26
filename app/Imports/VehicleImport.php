<?php

namespace App\Imports;

use App\Models\VehicleChassis;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\VehicleVin;
use Illuminate\Support\Str;

class VehicleImport extends BaseImport
{
    private const ALLOWED_FUEL_TYPES = [
        'Gasoline',
        'Diesel',
        'Hybrid',
        'Electric',
    ];

    public function getColumns(): array
    {
        return [
            ['key' => 'make', 'label' => 'Marca (MAKE)', 'type' => 'searchable', 'required' => true, 'endpoint' => '/vehicle-makes/all'],
            ['key' => 'model', 'label' => 'Modelo (MODEL)', 'type' => 'text', 'required' => true],
            ['key' => 'year', 'label' => 'Ano (YEAR)', 'type' => 'text', 'required' => true],
            ['key' => 'generation', 'label' => 'Generacion', 'type' => 'text'],
            ['key' => 'code', 'label' => 'Chasis (CODE)', 'type' => 'text'],
            ['key' => 'engine', 'label' => 'Motor (ENGINE)', 'type' => 'text'],
            ['key' => 'displacement', 'label' => 'Cilindrada', 'type' => 'text'],
            ['key' => 'fuel_type', 'label' => 'Combustible', 'type' => 'text'],
            ['key' => 'aspiration', 'label' => 'Aspiracion', 'type' => 'text'],
            ['key' => 'vin', 'label' => 'VIN', 'type' => 'text'],
        ];
    }

    public function getExampleRow(): array
    {
        return [
            'make' => 'TOYOTA',
            'model' => 'YARIS',
            'year' => '2000-2005',
            'generation' => 'P10',
            'code' => 'P10',
            'engine' => '1NZFE',
            'displacement' => '1.5L',
            'fuel_type' => 'Gasoline',
            'aspiration' => 'Naturally Aspirated',
            'vin' => 'MR2BT9F3101124478',
        ];
    }

    public function getEntityLabel(): string
    {
        return 'vehiculo';
    }

    protected function mapHeader(string $header): string
    {
        return match ($header) {
            'make', 'marca', 'marca_auto', 'vehicle_make' => 'make',
            'model', 'modelo', 'vehicle_model' => 'model',
            'year', 'ano', 'year_range', 'years' => 'year',
            'ano_desde', 'year_from' => 'year_from',
            'ano_hasta', 'year_to' => 'year_to',
            'generation', 'generacion' => 'generation',
            'code', 'codigo', 'chassis', 'chassis_code' => 'code',
            'engine', 'motor', 'engine_code' => 'engine',
            'displacement', 'cilindrada' => 'displacement',
            'fuel_type', 'combustible', 'tipo_combustible' => 'fuel_type',
            'aspiration', 'aspiracion' => 'aspiration',
            'vin', 'numero_vin', 'vin_number' => 'vin',
            default => $header,
        };
    }

    public function validateRow(array $row): array
    {
        $errors = [];

        if (trim((string) ($row['make'] ?? '')) === '') {
            $errors[] = 'El campo marca (MAKE) es obligatorio.';
        }

        if (trim((string) ($row['model'] ?? '')) === '') {
            $errors[] = 'El campo modelo (MODEL) es obligatorio.';
        }

        [$yearFrom, $yearTo] = $this->parseYearRangeFromRow($row);
        if ($yearFrom === null) {
            $errors[] = "El formato de ano es invalido. Use '2005', '2000-2005' o columnas ano_desde/ano_hasta.";
        } elseif ($yearTo !== null && $yearTo < $yearFrom) {
            $errors[] = 'El ano final no puede ser menor que el ano inicial.';
        }

        $engineCode = $this->nullIfEmpty($row['engine'] ?? null);
        if ($engineCode !== null && preg_match('/\s/u', $engineCode) === 1) {
            $errors[] = 'El codigo del motor no puede contener espacios.';
        }

        try {
            $this->normalizeFuelType($row['fuel_type'] ?? null);
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }

    public function processRow(array $row): mixed
    {
        $makeId = $this->resolveIdOrCreateByName(VehicleMake::class, 'name', $row['make']);
        [$yearFrom, $yearTo] = $this->parseYearRangeFromRow($row);

        $modelName = trim($row['model']);
        $model = VehicleModel::query()
            ->where('make_id', $makeId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($modelName)])
            ->first();

        $modelData = [
            'make_id' => $makeId,
            'name' => $modelName,
            'year_from' => $yearFrom,
            'year_to' => $yearTo ?? $yearFrom,
            'generation' => $this->nullIfEmpty($row['generation'] ?? null),
        ];

        if ($model) {
            $model->update(array_filter($modelData, fn ($value) => $value !== null));
        } else {
            $model = VehicleModel::create(array_filter($modelData, fn ($value) => $value !== null));
        }

        $chassisCode = $this->nullIfEmpty($row['code'] ?? null);
        $chassis = null;
        if ($chassisCode) {
            $chassis = VehicleChassis::query()
                ->whereRaw('LOWER(code) = ?', [mb_strtolower($chassisCode)])
                ->first();

            if (! $chassis) {
                $chassis = VehicleChassis::create([
                    'code' => strtoupper(trim($chassisCode)),
                    'name' => strtoupper(trim($chassisCode)),
                    'model_id' => $model->id,
                    'year_from' => $yearFrom,
                    'year_to' => $yearTo ?? $yearFrom,
                ]);
            }

            $model->chassis()->syncWithoutDetaching([$chassis->id]);
        }

        $engineCode = $this->nullIfEmpty($row['engine'] ?? null);
        $engine = null;
        if ($engineCode) {
            $normalizedEngineCode = strtoupper(trim($engineCode));
            $engine = VehicleEngine::query()
                ->where(function ($query) use ($model, $normalizedEngineCode) {
                    $query->where('model_id', $model->id)
                        ->orWhereHas('models', fn ($modelsQuery) => $modelsQuery->where('vehicle_models.id', $model->id));
                })
                ->whereRaw('LOWER(engine_code) = ?', [mb_strtolower($normalizedEngineCode)])
                ->first();

            $engineData = [
                'model_id' => $model->id,
                'engine_code' => $normalizedEngineCode,
                'displacement' => $this->nullIfEmpty($row['displacement'] ?? null),
                'fuel_type' => $this->normalizeFuelType($row['fuel_type'] ?? null),
                'aspiration' => $this->nullIfEmpty($row['aspiration'] ?? null),
            ];

            if ($engine) {
                $engine->update(array_filter($engineData, fn ($value) => $value !== null));
            } else {
                $engine = VehicleEngine::create(array_filter($engineData, fn ($value) => $value !== null));
            }

            $engine->models()->syncWithoutDetaching([$model->id]);
        }

        $vinStr = $this->nullIfEmpty($row['vin'] ?? null);
        if ($vinStr) {
            VehicleVin::firstOrCreate(
                [
                    'vin' => strtoupper(trim($vinStr)),
                    'model_id' => $model->id,
                ],
                [
                    'engine_id' => $engine?->id,
                ]
            );
        }

        return $model;
    }

    private function parseYearRangeFromRow(array $row): array
    {
        $yearFrom = $this->parseInteger($row['year_from'] ?? null);
        $yearTo = $this->parseInteger($row['year_to'] ?? null);

        if ($yearFrom !== null) {
            return [$yearFrom, $yearTo ?? $yearFrom];
        }

        return $this->parseYearRange($row['year'] ?? null);
    }

    private function parseYearRange(mixed $yearValue): array
    {
        $yearStr = trim((string) ($yearValue ?? ''));
        if ($yearStr === '') {
            return [null, null];
        }

        if (preg_match('/^(\d{4})\s*(?:-|\x{2013})\s*(\d{4})$/u', $yearStr, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        if (preg_match('/^(\d{4})$/', $yearStr, $matches) === 1) {
            $year = (int) $matches[1];

            return [$year, $year];
        }

        return [null, null];
    }

    private function normalizeFuelType(mixed $fuelType): ?string
    {
        $raw = trim((string) ($fuelType ?? ''));
        if ($raw === '') {
            return null;
        }

        $normalized = Str::ascii(mb_strtolower($raw));

        $fuelType = match ($normalized) {
            'gasoline', 'gasolina', 'petrol', 'nafta' => 'Gasoline',
            'diesel' => 'Diesel',
            'hybrid', 'hibrido' => 'Hybrid',
            'electric', 'electrico' => 'Electric',
            default => $raw,
        };

        if (! in_array($fuelType, self::ALLOWED_FUEL_TYPES, true)) {
            throw new \RuntimeException('Tipo de combustible invalido. Valores permitidos: Gasoline, Diesel, Hybrid, Electric.');
        }

        return $fuelType;
    }

    protected function getLabelColumn(): string
    {
        return 'model';
    }
}
