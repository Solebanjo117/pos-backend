<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SupplierImportTemplate implements FromArray, ShouldAutoSize, WithHeadings
{
    public function headings(): array
    {
        return [
            'nombre',
            'contacto',
            'email',
            'telefono',
            'direccion',
            'ciudad',
            'pais',
            'condiciones_pago',
            'dias_entrega',
            'notas',
            'estado',
        ];
    }

    public function array(): array
    {
        return [
            ['Distribuidora Nacional', 'Juan Pérez', 'juan@distri.com', '+507 123-4567', 'Calle 50', 'Colón', 'Panamá', 'Net 30', 7, 'Proveedor confiable', 'active'],
            ['Auto Parts International', 'María López', 'maria@autoparts.com', '+507 987-6543', 'Vía España', 'Panamá', 'Panamá', 'Net 15', 3, '', 'active'],
        ];
    }
}
