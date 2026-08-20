<?php

namespace App\Exports;

use App\Models\VehicleVin;
use App\Services\VehicleCatalogService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class VehicleCatalogExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function __construct(private VehicleCatalogService $catalogService) {}

    public function headings(): array
    {
        return ['MAKE', 'MODEL', 'YEAR', 'GENERATION', 'CODE', 'ENGINE', 'DISPLACEMENT', 'FUEL_TYPE', 'ASPIRATION', 'VIN'];
    }

    public function collection(): Collection
    {
        $rows = $this->catalogService->configurationPairsQuery()
            ->join('vehicle_models', 'vehicle_models.id', '=', 'vehicle_pairs.model_id')
            ->join('vehicle_makes', 'vehicle_makes.id', '=', 'vehicle_models.make_id')
            ->join('vehicle_engines', 'vehicle_engines.id', '=', 'vehicle_pairs.engine_id')
            ->select([
                'vehicle_pairs.model_id',
                'vehicle_pairs.engine_id',
                'vehicle_makes.name as make_name',
                'vehicle_models.name as model_name',
                'vehicle_models.year_from',
                'vehicle_models.year_to',
                'vehicle_models.generation',
                'vehicle_engines.engine_code',
                'vehicle_engines.displacement',
                'vehicle_engines.fuel_type',
                'vehicle_engines.aspiration',
            ])
            ->orderBy('vehicle_makes.name')
            ->orderBy('vehicle_models.name')
            ->orderBy('vehicle_engines.engine_code')
            ->get();

        $vins = VehicleVin::query()
            ->whereIn('model_id', $rows->pluck('model_id')->unique())
            ->whereIn('engine_id', $rows->pluck('engine_id')->unique())
            ->orderBy('vin')
            ->get()
            ->groupBy(fn (VehicleVin $vin): string => "{$vin->model_id}:{$vin->engine_id}");

        return $rows->map(function ($row) use ($vins): array {
            $year = (int) $row->year_from === (int) $row->year_to
                ? (string) $row->year_from
                : "{$row->year_from}-{$row->year_to}";
            $pairVins = $vins->get("{$row->model_id}:{$row->engine_id}", collect());

            return [
                $row->make_name,
                $row->model_name,
                $year,
                $row->generation,
                $row->generation,
                $row->engine_code,
                $row->displacement,
                $row->fuel_type,
                $row->aspiration,
                $pairVins->pluck('vin')->implode("\n"),
            ];
        });
    }
}
