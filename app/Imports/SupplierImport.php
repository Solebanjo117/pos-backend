<?php

namespace App\Imports;

use App\Models\Brand;
use App\Models\Supplier;

class SupplierImport extends BaseImport
{
    public function getColumns(): array
    {
        return [
            ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text', 'required' => true],
            ['key' => 'contacto', 'label' => 'Contacto', 'type' => 'text'],
            ['key' => 'email', 'label' => 'Email', 'type' => 'text'],
            ['key' => 'telefono', 'label' => 'Telefono', 'type' => 'text'],
            ['key' => 'direccion', 'label' => 'Direccion', 'type' => 'text'],
            ['key' => 'ciudad', 'label' => 'Ciudad', 'type' => 'text'],
            ['key' => 'pais', 'label' => 'Pais', 'type' => 'text'],
            ['key' => 'condiciones_pago', 'label' => 'Condiciones de Pago', 'type' => 'text'],
            ['key' => 'dias_entrega', 'label' => 'Dias de Entrega', 'type' => 'number'],
            ['key' => 'notas', 'label' => 'Notas', 'type' => 'text'],
            ['key' => 'estado', 'label' => 'Estado', 'type' => 'text'],
            ['key' => 'marcas', 'label' => 'Marcas', 'type' => 'text'],
        ];
    }

    public function getExampleRow(): array
    {
        return [
            'nombre' => 'Distribuidora Nacional',
            'contacto' => 'Juan Perez',
            'email' => 'juan@distri.com',
            'telefono' => '+507 123-4567',
            'direccion' => 'Calle 50, Zona Libre',
            'ciudad' => 'Colon',
            'pais' => 'Panama',
            'condiciones_pago' => 'Net 30',
            'dias_entrega' => 7,
            'notas' => 'Proveedor confiable',
            'estado' => 'activo',
            'marcas' => 'Bosch, ACDelco',
        ];
    }

    public function getEntityLabel(): string
    {
        return 'proveedor';
    }

    protected function mapHeader(string $header): string
    {
        return match ($header) {
            'nombre', 'name' => 'nombre',
            'contacto', 'contact_name' => 'contacto',
            'email' => 'email',
            'telefono', 'phone' => 'telefono',
            'direccion', 'address' => 'direccion',
            'ciudad', 'city' => 'ciudad',
            'pais', 'country' => 'pais',
            'condiciones_pago', 'payment_terms' => 'condiciones_pago',
            'dias_entrega', 'lead_time_days' => 'dias_entrega',
            'notas', 'notes' => 'notas',
            'estado', 'status' => 'estado',
            'marcas', 'brands' => 'marcas',
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

        $email = trim((string) ($row['email'] ?? ''));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'El email no es valido.';
        }

        $status = $this->normalizeStatus($row['estado'] ?? null, '');
        if ($status !== '' && ! in_array($status, ['active', 'inactive'], true)) {
            $errors[] = 'El estado debe ser "active/activo" o "inactive/inactivo".';
        }

        $brandsRaw = $this->nullIfEmpty($row['marcas'] ?? null);
        if ($brandsRaw) {
            foreach ($this->splitDelimitedValues($brandsRaw) as $brandName) {
                $count = Brand::query()->where('normalized_name', Brand::normalizeName($brandName))->count();
                if ($count !== 1) {
                    $errors[] = $count === 0
                        ? "La marca '{$brandName}' no existe; créala antes de importar."
                        : "La marca '{$brandName}' es ambigua.";
                }
            }
        }

        return $errors;
    }

    public function processRow(array $row): mixed
    {
        $name = trim($row['nombre']);
        $status = $this->normalizeStatus($row['estado'] ?? null);

        $existing = Supplier::query()
            ->where('normalized_name', Supplier::normalizeName($name))
            ->first();

        $data = [
            'name' => $name,
            'contact_name' => $this->nullIfEmpty($row['contacto'] ?? null),
            'email' => $this->nullIfEmpty($row['email'] ?? null),
            'phone' => $this->nullIfEmpty($row['telefono'] ?? null),
            'address' => $this->nullIfEmpty($row['direccion'] ?? null),
            'city' => $this->nullIfEmpty($row['ciudad'] ?? null),
            'country' => $this->nullIfEmpty($row['pais'] ?? null),
            'payment_terms' => $this->nullIfEmpty($row['condiciones_pago'] ?? null),
            'lead_time_days' => $this->parseInteger($row['dias_entrega'] ?? null),
            'notes' => $this->nullIfEmpty($row['notas'] ?? null),
            'status' => $status,
        ];

        if ($existing) {
            $existing->update(array_filter($data, fn ($v) => $v !== null));
            $supplier = $existing;
        } else {
            $supplier = Supplier::create(array_filter($data, fn ($v) => $v !== null));
        }

        $brandsRaw = $this->nullIfEmpty($row['marcas'] ?? null);
        if ($brandsRaw) {
            foreach ($this->splitDelimitedValues($brandsRaw) as $brandName) {
                $brandId = Brand::query()->where('normalized_name', Brand::normalizeName($brandName))->sole()->id;
                $supplier->brands()->syncWithoutDetaching([$brandId]);
            }
        }

        return $supplier;
    }

    protected function getLabelColumn(): string
    {
        return 'nombre';
    }
}
