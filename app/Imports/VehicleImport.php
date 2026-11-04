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
            ['key' => 'code', 'label' => 'Chasis (CODE, opcional)', 'type' => 'text'],
            ['key' => 'engine', 'label' => 'Motor (ENGINE)', 'type' => 'text'],
            ['key' => 'nickname', 'label' => 'Apodo del motor', 'type' => 'text'],
            ['key' => 'displacement', 'label' => 'Cilindrada', 'type' => 'text'],
            ['key' => 'valves', 'label' => 'Valvulas (VALVE)', 'type' => 'text'],
            ['key' => 'cam_type', 'label' => 'Arbol de levas (CAM)', 'type' => 'text'],
            ['key' => 'cylinders', 'label' => 'Cilindros (CYLINDER)', 'type' => 'text'],
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
            'nickname' => '',
            'displacement' => '1.5L',
            'valves' => '16V',
            'cam_type' => 'DOHC',
            'cylinders' => '4',
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
            'make', 'make_name', 'marca', 'marca_auto', 'vehicle_make' => 'make',
            'model', 'model_name', 'modelo', 'vehicle_model' => 'model',
            'year', 'ano', 'year_range', 'years' => 'year',
            'ano_desde', 'year_from' => 'year_from',
            'ano_hasta', 'year_to' => 'year_to',
            'generation', 'generacion' => 'generation',
            'code', 'codigo', 'chassis', 'chassis_code' => 'code',
            'engine', 'motor', 'engine_code' => 'engine',
            'engine_specs', 'engine_spec', 'especificaciones_motor', 'specs' => 'engine_specs',
            'displacement', 'cilindrada', 'liter', 'liters', 'litros' => 'displacement',
            'valves', 'valve', 'valvulas' => 'valves',
            'cam', 'cam_type', 'arbol_levas', 'arbol_de_levas' => 'cam_type',
            'cylinders', 'cylinder', 'cilindros' => 'cylinders',
            'nickname', 'apodo', 'apodo_motor' => 'nickname',
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

        $vinValues = $this->splitDelimitedValues($row['vin'] ?? null);
        if ($vinValues !== [] && $engineCode === null) {
            $errors[] = 'Para importar VINs se requiere el campo motor (ENGINE).';
        }

        foreach ($vinValues as $vin) {
            if (mb_strlen($vin) > 40) {
                $errors[] = "El VIN '{$vin}' no puede tener mas de 40 caracteres.";
            }
        }

        try {
            $this->normalizeFuelType($row['fuel_type'] ?? null);
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        $specs = $this->engineSpecValues($this->withEngineSpecs($row));
        if ($specs['valves'] !== null && preg_match('/^\d{1,2}V$/', $specs['valves']) !== 1) {
            $errors[] = 'Las valvulas deben indicarse como numero, por ejemplo 16 o 16V.';
        }
        if ($specs['cam_type'] !== null && ! in_array($specs['cam_type'], ['SOHC', 'DOHC', 'OHV'], true)) {
            $errors[] = 'El arbol de levas debe ser SOHC, DOHC u OHV.';
        }
        $cylinders = $this->nullIfEmpty($row['cylinders'] ?? null);
        if ($cylinders !== null && (filter_var($cylinders, FILTER_VALIDATE_INT) === false || (int) $cylinders < 1 || (int) $cylinders > 16)) {
            $errors[] = 'El numero de cilindros debe estar entre 1 y 16.';
        }

        return $errors;
    }

    protected function mapRowToPreview(array $row, ?array $errors = null, ?array $warnings = null): array
    {
        return parent::mapRowToPreview($this->withEngineSpecs($row), $errors, $warnings);
    }

    public function processRow(array $row): mixed
    {
        $row = $this->withEngineSpecs($row);
        $makeId = $this->resolveIdOrCreateByName(VehicleMake::class, 'name', $row['make']);
        [$yearFrom, $yearTo] = $this->parseYearRangeFromRow($row);

        $modelName = trim($row['model']);
        $generation = $this->nullIfEmpty($row['generation'] ?? null) ?? $this->nullIfEmpty($row['code'] ?? null);
        $model = VehicleModel::query()
            ->where('make_id', $makeId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($modelName)])
            ->when($generation, fn ($query) => $query->whereRaw('LOWER(generation) = ?', [mb_strtolower($generation)]))
            ->when(! $generation && $yearFrom !== null, fn ($query) => $query->where('year_from', $yearFrom)->where('year_to', $yearTo ?? $yearFrom))
            ->first();

        $modelData = [
            'make_id' => $makeId,
            'name' => $modelName,
            'year_from' => $yearFrom,
            'year_to' => $yearTo ?? $yearFrom,
            'generation' => $generation,
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
                ->where(function ($query) use ($model) {
                    $query->where('model_id', $model->id)
                        ->orWhereHas('models', fn ($modelsQuery) => $modelsQuery->where('vehicle_models.id', $model->id));
                })
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

        $engineCode = $this->normalizeEngineCode($row['engine'] ?? null);
        $engine = null;
        if ($engineCode) {
            $specs = $this->engineSpecValues($row);
            $candidates = VehicleEngine::query()
                ->where(function ($query) use ($makeId) {
                    $query->where('make_id', $makeId)
                        ->orWhereHas('model', fn ($modelQuery) => $modelQuery->where('make_id', $makeId))
                        ->orWhereHas('models', fn ($modelsQuery) => $modelsQuery->where('make_id', $makeId));
                })
                ->whereRaw('LOWER(TRIM(engine_code)) = ?', [mb_strtolower($engineCode)])
                ->orderBy('id')
                ->get();
            $engine = $this->matchEngineVersion($candidates, $specs);

            $engineData = [
                'make_id' => $makeId,
                'model_id' => $model->id,
                'engine_code' => $engineCode,
                'nickname' => $specs['nickname'],
                'displacement' => $specs['displacement'],
                'valves' => $specs['valves'],
                'cam_type' => $specs['cam_type'],
                'cylinders' => $specs['cylinders'],
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
            foreach ($this->splitDelimitedValues($vinStr) as $vinValue) {
                VehicleVin::firstOrCreate([
                    'model_id' => $model->id,
                    'chassis_id' => $chassis?->id,
                    'engine_id' => $engine?->id,
                    'vin' => strtoupper(trim($vinValue)),
                ]);
            }
        }

        return $model;
    }

    /**
     * Completa cilindrada, aspiración y combustible vacíos a partir de la columna
     * libre de especificaciones (ej. "3.2L NA I6 (338 hp)").
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withEngineSpecs(array $row): array
    {
        $specs = trim((string) ($row['engine_specs'] ?? ''));
        if ($specs === '') {
            return $row;
        }

        if ($this->nullIfEmpty($row['displacement'] ?? null) === null
            && preg_match('/(\d+(?:[.,]\d+)?)\s*L\b/i', $specs, $matches) === 1) {
            $row['displacement'] = str_replace(',', '.', $matches[1]).'L';
        }

        if ($this->nullIfEmpty($row['valves'] ?? null) === null
            && preg_match('/\b(\d{1,2})\s*V\b/i', $specs, $matches) === 1) {
            $row['valves'] = $matches[1].'V';
        }

        if ($this->nullIfEmpty($row['cam_type'] ?? null) === null
            && preg_match('/\b(SOHC|DOHC|OHV)\b/i', $specs, $matches) === 1) {
            $row['cam_type'] = strtoupper($matches[1]);
        }

        if ($this->nullIfEmpty($row['aspiration'] ?? null) === null) {
            $row['aspiration'] = match (true) {
                preg_match('/\bturbo\b/i', $specs) === 1 => 'Turbo',
                preg_match('/\bsupercharged\b/i', $specs) === 1 => 'Supercharged',
                preg_match('/\bNA\b|naturally aspirated/', $specs) === 1 => 'Naturally Aspirated',
                default => $row['aspiration'] ?? null,
            };
        }

        if ($this->nullIfEmpty($row['fuel_type'] ?? null) === null) {
            $row['fuel_type'] = match (true) {
                preg_match('/\bdiesel\b/i', $specs) === 1 => 'Diesel',
                preg_match('/\bh[ií]brido\b|\bhybrid\b/iu', $specs) === 1 => 'Hybrid',
                preg_match('/\bel[eé]ctrico\b|\belectric\b/iu', $specs) === 1 => 'Electric',
                default => $row['fuel_type'] ?? null,
            };
        }

        return $row;
    }

    /**
     * Ficha del motor normalizada desde la fila: "16" -> "16V", "dohc" -> "DOHC".
     *
     * @param  array<string, mixed>  $row
     * @return array{nickname: ?string, displacement: ?string, valves: ?string, cam_type: ?string, cylinders: ?int}
     */
    private function engineSpecValues(array $row): array
    {
        $valves = $this->nullIfEmpty($row['valves'] ?? null);
        $valves = $valves === null ? null : mb_strtoupper(str_replace(' ', '', $valves));
        if ($valves !== null && ! str_ends_with($valves, 'V')) {
            $valves .= 'V';
        }

        $camType = $this->nullIfEmpty($row['cam_type'] ?? null);
        $nickname = $this->nullIfEmpty($row['nickname'] ?? null);
        $cylinders = $this->nullIfEmpty($row['cylinders'] ?? null);

        return [
            'nickname' => $nickname === null ? null : mb_strtoupper($nickname),
            'displacement' => $this->nullIfEmpty($row['displacement'] ?? null),
            'valves' => $valves,
            'cam_type' => $camType === null ? null : mb_strtoupper($camType),
            'cylinders' => filter_var($cylinders, FILTER_VALIDATE_INT) === false ? null : (int) $cylinders,
        ];
    }

    /**
     * Elige la versión del motor que corresponde a la ficha de la fila.
     *
     * Coincide si cada dato de la ficha (cilindrada, válvulas, árbol de levas) es igual o falta en
     * alguno de los dos lados. Si ninguna versión es compatible se devuelve null y se crea otra
     * versión del mismo código (p. ej. 6G72 12V y 6G72 24V).
     *
     * @param  \Illuminate\Support\Collection<int, VehicleEngine>  $candidates
     * @param  array{displacement: ?string, valves: ?string, cam_type: ?string}  $specs
     */
    private function matchEngineVersion($candidates, array $specs): ?VehicleEngine
    {
        $key = fn (mixed $value): ?string => $value === null || trim((string) $value) === ''
            ? null
            : mb_strtoupper(str_replace(' ', '', (string) $value));

        $compatible = $candidates->filter(function (VehicleEngine $engine) use ($specs, $key): bool {
            foreach (['displacement', 'valves', 'cam_type'] as $field) {
                $existing = $key($engine->{$field});
                $incoming = $key($specs[$field] ?? null);
                if ($existing !== null && $incoming !== null && $existing !== $incoming) {
                    return false;
                }
            }

            return true;
        });

        $exact = $compatible->first(function (VehicleEngine $engine) use ($specs, $key): bool {
            foreach (['displacement', 'valves', 'cam_type'] as $field) {
                if ($key($engine->{$field}) !== $key($specs[$field] ?? null)) {
                    return false;
                }
            }

            return true;
        });

        return $exact ?? $compatible->first();
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

    private function normalizeEngineCode(mixed $engineCode): ?string
    {
        $raw = $this->nullIfEmpty($engineCode);
        if ($raw === null) {
            return null;
        }

        return (string) preg_replace('/\s+/u', ' ', mb_strtoupper(trim($raw)));
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
