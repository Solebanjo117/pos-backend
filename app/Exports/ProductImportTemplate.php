<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ProductImportTemplate implements FromArray, WithHeadings, ShouldAutoSize
{
    public function headings(): array
    {
        return [
            'nombre',
            'sku',
            'descripcion',
            'marca',
            'categoria',
            'costo',
            'precio',
            'sku_variante',
            'proveedor',
        ];
    }

    public function array(): array
    {
        return [
            ['Bujia estandar', 'SKU-001', 'Bujia para motor gasolina', 'Genérica', 'General', 2.5, 5.0, 'SKU-001-A', 'Proveedor General'],
            ['Filtro de aire', 'SKU-002', 'Filtro universal', 'Genérica', 'General', 4.25, 8.5, 'SKU-002-A', 'Proveedor General'],
        ];
    }
}
