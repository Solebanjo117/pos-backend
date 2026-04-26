<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class BrandImportTemplate implements FromArray, ShouldAutoSize, WithHeadings
{
    public function headings(): array
    {
        return [
            'nombre',
            'descripcion',
            'website',
            'estado',
            'proveedores',
        ];
    }

    public function array(): array
    {
        return [
            ['Bosch', 'Fabricante aleman de autopartes', 'https://www.bosch.com', 'activo', 'Proveedor General'],
            ['ACDelco', 'Repuestos GM originales', '', 'active', 'Proveedor General, Distribuidora Nacional'],
        ];
    }
}
