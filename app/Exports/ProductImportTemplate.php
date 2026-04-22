<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ProductImportTemplate implements FromArray, ShouldAutoSize, WithHeadings
{
    public function headings(): array
    {
        return ['nombre', 'sku', 'descripcion', 'categoria'];
    }

    public function array(): array
    {
        return [
            ['Bujía Iridium', 'SKU-001', 'Bujía para motor gasolina', 'Encendido'],
            ['Filtro de Aire', 'SKU-002', 'Filtro universal', 'Filtros'],
        ];
    }
}
