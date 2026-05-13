<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ProductImportTemplate implements FromArray, ShouldAutoSize, WithHeadings
{
    public function headings(): array
    {
        return [
            'nombre',
            'sku',
            'descripcion',
            'categoria',
            'categorias_extra',
            'atributos',
            'make',
            'model',
            'year',
            'code',
            'engine',
            'vin',
            'upsale_path',
        ];
    }

    public function array(): array
    {
        return [
            ['Bujia Iridium', 'SKU-001', 'Bujia para motor gasolina', 'Encendido', 'Motor', 'color:rojo,largo:15cm', 'TOYOTA', 'YARIS', '2006-2013', 'P90', '1NZFE', 'JTDBT933X01109408', 'motor/encendido'],
            ['Filtro de Aire', 'SKU-002', 'Filtro universal', 'Filtros', '', 'alto:8cm,diametro:12cm', 'TOYOTA', 'YARIS', '2000-2005', 'P10', '', '', 'motor/filtros'],
        ];
    }
}
