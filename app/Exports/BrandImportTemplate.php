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
            'prefijo',
            'descripcion',
            'website',
            'estado',
            'proveedores',
        ];
    }

    public function array(): array
    {
        return [
            ['Bosch', 'BOS', 'Fabricante alemán de autopartes', 'https://www.bosch.com', 'active', 'Proveedor General'],
            ['ACDelco', 'ACD', 'Repuestos GM originales', '', 'active', 'Proveedor General, Distribuidora Nacional'],
        ];
    }
}
