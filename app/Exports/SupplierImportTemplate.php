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
            'marcas',
        ];
    }

    public function array(): array
    {
        return [
            ['Distribuidora Nacional', 'Juan Perez', 'juan@distri.com', '+507 123-4567', 'Calle 50', 'Colon', 'Panama', 'Net 30', 7, 'Proveedor confiable', 'activo', 'Bosch, ACDelco'],
            ['Auto Parts International', 'Maria Lopez', 'maria@autoparts.com', '+507 987-6543', 'Via Espana', 'Panama', 'Panama', 'Net 15', 3, '', 'active', ''],
        ];
    }
}
