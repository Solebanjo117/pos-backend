<?php

namespace App\Imports;

use App\Models\Brand;
use App\Models\Supplier;

class BrandImport extends BaseImport
{
    public function getColumns(): array
    {
        return [
            ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text', 'required' => true],
            ['key' => 'prefijo', 'label' => 'Prefijo', 'type' => 'text'],
            ['key' => 'descripcion', 'label' => 'Descripción', 'type' => 'text'],
            ['key' => 'website', 'label' => 'Website', 'type' => 'text'],
            ['key' => 'estado', 'label' => 'Estado', 'type' => 'text'],
            ['key' => 'proveedores', 'label' => 'Proveedores', 'type' => 'text'],
        ];
    }

    public function getExampleRow(): array
    {
        return [
            'nombre' => 'Bosch',
            'prefijo' => 'BOS',
            'descripcion' => 'Fabricante alemán de autopartes',
            'website' => 'https://www.bosch.com',
            'estado' => 'active',
            'proveedores' => 'Proveedor General, Distribuidora Nacional',
        ];
    }

    public function getEntityLabel(): string
    {
        return 'marca';
    }

    protected function mapHeader(string $header): string
    {
        return match ($header) {
            'nombre', 'name' => 'nombre',
            'prefijo', 'prefix' => 'prefijo',
            'descripcion', 'description' => 'descripcion',
            'website' => 'website',
            'estado', 'status' => 'estado',
            'proveedores', 'suppliers' => 'proveedores',
            default => $header,
        };
    }

    public function validateRow(array $row): array
    {
        $errors = [];
        $name = trim((string) ($row['nombre'] ?? ''));

        if ($name === '') {
            $errors[] = 'El campo nombre es obligatorio.';
        }

        $status = trim((string) ($row['estado'] ?? ''));
        if ($status !== '' && ! in_array($status, ['active', 'inactive'])) {
            $errors[] = 'El estado debe ser "active" o "inactive".';
        }

        return $errors;
    }

    public function processRow(array $row): mixed
    {
        $name = trim($row['nombre']);
        $status = $this->nullIfEmpty($row['estado'] ?? null) ?? 'active';

        $existing = Brand::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        $data = [
            'name' => $name,
            'prefix' => $this->nullIfEmpty($row['prefijo'] ?? null),
            'description' => $this->nullIfEmpty($row['descripcion'] ?? null),
            'website' => $this->nullIfEmpty($row['website'] ?? null),
            'status' => $status,
        ];

        if ($existing) {
            $existing->update(array_filter($data, fn ($v) => $v !== null));
            $brand = $existing;
        } else {
            $brand = Brand::create(array_filter($data, fn ($v) => $v !== null));
        }

        $suppliersRaw = $this->nullIfEmpty($row['proveedores'] ?? null);
        if ($suppliersRaw) {
            $supplierNames = array_map('trim', explode(',', $suppliersRaw));
            foreach ($supplierNames as $supplierName) {
                if ($supplierName === '') {
                    continue;
                }
                $supplierId = $this->resolveOrCreate(Supplier::class, 'name', $supplierName, ['status' => 'active']);
                $brand->suppliers()->syncWithoutDetaching([$supplierId]);
            }
        }

        return $brand;
    }

    protected function getLabelColumn(): string
    {
        return 'nombre';
    }
}
