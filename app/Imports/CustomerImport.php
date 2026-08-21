<?php

namespace App\Imports;

use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\CustomerVehicle;
use App\Models\VehicleEngine;
use App\Models\VehicleModel;
use App\Services\CustomerVehicleVinSyncService;

class CustomerImport extends BaseImport
{
    public function getColumns(): array
    {
        return collect($this->getExampleRow())->keys()->map(fn ($key) => ['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'type' => 'text', 'required' => $key === 'nombre'])->all();
    }

    public function getExampleRow(): array
    {
        return ['codigo' => '', 'nombre' => 'Ana Pérez', 'empresa' => '', 'tipo' => 'Individual', 'ruc' => '', 'telefono' => '', 'email' => '', 'direccion' => '', 'notas' => '', 'marca_vehiculo' => '', 'modelo_vehiculo' => '', 'motor_vehiculo' => '', 'anio' => '', 'vin' => '', 'placa' => ''];
    }

    public function getEntityLabel(): string
    {
        return 'cliente';
    }

    protected function getLabelColumn(): string
    {
        return 'nombre';
    }

    public function validateRow(array $row): array
    {
        $errors = [];
        if (trim((string) ($row['nombre'] ?? '')) === '') {
            $errors[] = 'El nombre es obligatorio.';
        }
        $email = trim((string) ($row['email'] ?? ''));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'El email no es válido.';
        }

        $type = CustomerType::query()->where('normalized_name', CustomerType::normalizeName((string) ($row['tipo'] ?? 'Individual')))->get();
        if ($type->count() !== 1 || ! $type->first()->is_active) {
            $errors[] = 'El tipo de cliente no existe, está inactivo o es ambiguo.';
        }

        $vehicleFields = collect(['marca_vehiculo', 'modelo_vehiculo', 'motor_vehiculo'])->filter(fn ($field) => trim((string) ($row[$field] ?? '')) !== '');
        if ($vehicleFields->isNotEmpty() && $vehicleFields->count() !== 3) {
            $errors[] = 'Marca, modelo y motor son obligatorios para asociar un vehículo.';
        } elseif ($vehicleFields->count() === 3 && ! $this->resolveVehicle($row)) {
            $errors[] = 'La combinación Marca–Modelo–Motor no existe o es ambigua.';
        }

        return $errors;
    }

    public function processRow(array $row): mixed
    {
        $type = CustomerType::query()->where('normalized_name', CustomerType::normalizeName((string) ($row['tipo'] ?? 'Individual')))->sole();
        $code = trim((string) ($row['codigo'] ?? ''));
        $taxId = trim((string) ($row['ruc'] ?? ''));
        $customer = Customer::query()
            ->when($code !== '', fn ($query) => $query->where('code', $code), fn ($query) => $query->where('name', 'ilike', trim((string) $row['nombre']))->when($taxId !== '', fn ($query) => $query->where('tax_id', $taxId)))
            ->first();

        $data = [
            'code' => $code !== '' ? $code : ($customer?->code ?? Customer::generateCode()),
            'name' => trim((string) $row['nombre']), 'tax_name' => $this->nullIfEmpty($row['empresa'] ?? null),
            'customer_type_id' => $type->id, 'type' => $type->base_kind, 'tax_id' => $this->nullIfEmpty($row['ruc'] ?? null),
            'phone' => $this->nullIfEmpty($row['telefono'] ?? null), 'email' => $this->nullIfEmpty($row['email'] ?? null),
            'address' => $this->nullIfEmpty($row['direccion'] ?? null), 'notes' => $this->nullIfEmpty($row['notas'] ?? null),
        ];
        $customer ? $customer->update($data) : $customer = Customer::create($data);

        if ($vehicle = $this->resolveVehicle($row)) {
            $vin = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($row['vin'] ?? '')));
            $association = CustomerVehicle::query()->firstOrNew([
                'customer_id' => $customer->id, 'vehicle_model_id' => $vehicle['model']->id,
                'vehicle_engine_id' => $vehicle['engine']->id, 'vin' => $vin !== '' ? $vin : null,
            ]);
            $association->fill(['year' => $this->parseInteger($row['anio'] ?? null), 'plate_number' => $this->nullIfEmpty($row['placa'] ?? null), 'status' => 'active']);
            $association->save();
            app(CustomerVehicleVinSyncService::class)->sync($association);
        }

        return $customer;
    }

    private function resolveVehicle(array $row): ?array
    {
        $make = trim((string) ($row['marca_vehiculo'] ?? ''));
        $model = trim((string) ($row['modelo_vehiculo'] ?? ''));
        $engine = trim((string) ($row['motor_vehiculo'] ?? ''));
        if ($make === '' && $model === '' && $engine === '') {
            return null;
        }

        $models = VehicleModel::query()->where('name', 'ilike', $model)->whereHas('make', fn ($query) => $query->where('name', 'ilike', $make))->get();
        if ($models->count() !== 1) {
            return null;
        }
        $engines = VehicleEngine::query()->where('engine_code', 'ilike', $engine)->whereHas('models', fn ($query) => $query->whereKey($models->first()->id))->get();
        if ($engines->count() !== 1) {
            return null;
        }

        return ['model' => $models->first(), 'engine' => $engines->first()];
    }
}
