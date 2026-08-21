<?php

namespace App\Exports;

use App\Models\Customer;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CustomerExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function headings(): array
    {
        return ['codigo', 'nombre', 'empresa', 'tipo', 'ruc', 'telefono', 'email', 'direccion', 'notas', 'marca_vehiculo', 'modelo_vehiculo', 'motor_vehiculo', 'anio', 'vin', 'placa'];
    }

    public function collection(): Collection
    {
        return Customer::query()->with(['customerType:id,name', 'vehicles.model.make', 'vehicles.engine'])->orderBy('name')->get()->flatMap(function (Customer $customer): array {
            $vehicles = $customer->vehicles->where('status', 'active');
            if ($vehicles->isEmpty()) {
                $vehicles = collect([null]);
            }

            return $vehicles->map(fn ($vehicle): array => [
                $customer->code, $customer->name, $customer->tax_name, $customer->customerType?->name, $customer->tax_id,
                $customer->phone, $customer->email, $customer->address, $customer->notes,
                $vehicle?->model?->make?->name, $vehicle?->model?->name, $vehicle?->engine?->engine_code,
                $vehicle?->year, $vehicle?->vin, $vehicle?->plate_number,
            ])->all();
        });
    }
}
