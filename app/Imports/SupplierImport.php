<?php

namespace App\Imports;

use App\Models\Supplier;

class SupplierImport extends BaseImport
{
    public function getColumns(): array
    {
        return [
            ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text', 'required' => true],
            ['key' => 'contacto', 'label' => 'Contacto', 'type' => 'text'],
            ['key' => 'email', 'label' => 'Email', 'type' => 'text'],
            ['key' => 'telefono', 'label' => 'Teléfono', 'type' => 'text'],
            ['key' => 'direccion', 'label' => 'Dirección', 'type' => 'text'],
            ['key' => 'ciudad', 'label' => 'Ciudad', 'type' => 'text'],
            ['key' => 'pais', 'label' => 'País', 'type' => 'text'],
            ['key' => 'condiciones_pago', 'label' => 'Condiciones de Pago', 'type' => 'text'],
            ['key' => 'dias_entrega', 'label' => 'Días de Entrega', 'type' => 'number'],
            ['key' => 'notas', 'label' => 'Notas', 'type' => 'text'],
            ['key' => 'estado', 'label' => 'Estado', 'type' => 'text'],
        ];
    }

    public function getExampleRow(): array
    {
        return [
            'nombre' => 'Distribuidora Nacional',
            'contacto' => 'Juan Pérez',
            'email' => 'juan@distri.com',
            'telefono' => '+507 123-4567',
            'direccion' => 'Calle 50, Zona Libre',
            'ciudad' => 'Colón',
            'pais' => 'Panamá',
            'condiciones_pago' => 'Net 30',
            'dias_entrega' => 7,
            'notas' => 'Proveedor confiable',
            'estado' => 'active',
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
            $errors[] = 'El email no es válido.';
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

        $existing = Supplier::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
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
            'lead_time_days' => $row['dias_entrega'] ?? null,
            'notes' => $this->nullIfEmpty($row['notas'] ?? null),
            'status' => $status,
        ];

        if ($existing) {
            $existing->update(array_filter($data, fn ($v) => $v !== null));

            return $existing;
        }

        return Supplier::create(array_filter($data, fn ($v) => $v !== null));
    }

    protected function getLabelColumn(): string
    {
        return 'nombre';
    }
}
