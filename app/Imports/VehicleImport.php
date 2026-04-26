<?php

namespace App\Imports;

use App\Models\VehicleChassis;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\VehicleVin;

class VehicleImport extends BaseImport
{
    public function getColumns(): array
    {
        return [
            ['key' => 'make',   'label' => 'Marca (MAKE)',   'type' => 'searchable', 'required' => true, 'endpoint' => '/vehicle-makes/all'],
            ['key' => 'model',  'label' => 'Modelo (MODEL)', 'type' => 'text',       'required' => true],
            ['key' => 'year',   'label' => 'Año (YEAR)',     'type' => 'text',       'required' => true],
            ['key' => 'code',   'label' => 'Código (CODE)',  'type' => 'text'],
            ['key' => 'engine', 'label' => 'Motor (ENGINE)', 'type' => 'text'],
            ['key' => 'vin',    'label' => 'VIN',            'type' => 'text'],
        ];
    }

    public function getExampleRow(): array
    {
        return [
            'make' => 'TOYOTA',
            'model' => 'YARIS',
            'year' => '2000-2005',
            'code' => 'P10',
            'engine' => '1NZFE',
            'vin' => 'MR2BT9F3101124478',
        ];
    }

    public function getEntityLabel(): string
    {
        return 'vehículo';
    }

    protected function mapHeader(string $header): string
    {
        return match ($header) {
            'make', 'marca', 'marca_auto', 'vehicle_make' => 'make',
            'model', 'modelo', 'vehicle_model' => 'model',
            'year', 'año', 'ano', 'year_range', 'years' => 'year',
            'code', 'codigo', 'código', 'chassis', 'chassis_code' => 'code',
            'engine', 'motor', 'engine_code' => 'engine',
            'vin', 'numero_vin', 'vin_number' => 'vin',
            // Legacy headers from old format
            'marca_auto' => 'make',
            'modelo' => 'model',
            'ano_desde' => 'year',
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

        $yearStr = trim((string) ($row['year'] ?? ''));
        if ($yearStr === '') {
            $errors[] = 'El campo año (YEAR) es obligatorio.';
        } else {
            [$yearFrom] = $this->parseYearRange($yearStr);
            if ($yearFrom === null) {
                $errors[] = "El formato de año es inválido. Use '2005' o '2000-2005'.";
            }
        }

        return $errors;
    }

    public function processRow(array $row): mixed
    {
        // 1. Resolve or create Make
        $makeId = $this->resolveIdOrCreateByName(VehicleMake::class, 'name', $row['make']);

        // 2. Parse year range
        [$yearFrom, $yearTo] = $this->parseYearRange($row['year']);

        // 3. Resolve or create Model (under make + matching name)
        $modelName = trim($row['model']);
        $model = VehicleModel::query()
            ->where('make_id', $makeId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($modelName)])
            ->first();

        if (! $model) {
            $model = VehicleModel::create([
                'make_id' => $makeId,
                'name' => $modelName,
                'year_from' => $yearFrom,
                'year_to' => $yearTo ?? $yearFrom,
            ]);
        }

        // 4. Resolve or create Chassis (CODE) and link to model
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

            // Link chassis to model (idempotent)
            $model->chassis()->syncWithoutDetaching([$chassis->id]);
        }

        // 5. Resolve or create Engine and link to model
        $engineCode = $this->nullIfEmpty($row['engine'] ?? null);
        $engine = null;
        if ($engineCode) {
            $engine = VehicleEngine::query()
                ->where('model_id', $model->id)
                ->whereRaw('LOWER(engine_code) = ?', [mb_strtolower($engineCode)])
                ->first();

            if (! $engine) {
                $engine = VehicleEngine::create([
                    'model_id' => $model->id,
                    'engine_code' => strtoupper(trim($engineCode)),
                    'fuel_type' => 'N/A',
                    'aspiration' => 'N/A',
                ]);
            }
        }

        // 6. Create VIN (optional, plain text, stored as-is)
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

    /**
     * Parse a year string like "2000-2005", "2000", "2000 - 2005".
     * Returns [year_from, year_to] or [null, null] on failure.
     */
    private function parseYearRange(?string $yearStr): array
    {
        if (! $yearStr || trim($yearStr) === '') {
            return [null, null];
        }

        $yearStr = trim($yearStr);

        // Range: "2000-2005" or "2000 - 2005" (handle en-dash too)
        if (preg_match('/^(\d{4})\s*[-–]\s*(\d{4})$/', $yearStr, $matches)) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        // Single year: "2005"
        if (preg_match('/^(\d{4})$/', $yearStr, $matches)) {
            $y = (int) $matches[1];

            return [$y, $y];
        }

        return [null, null];
    }

    protected function getLabelColumn(): string
    {
        return 'model';
    }
}
