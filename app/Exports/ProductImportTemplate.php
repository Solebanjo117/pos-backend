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
            'system_id',
            'brand',
            'categoria',
            'costo',
            'precio',
            'cantidad',
            'descripcion',
            'vendor',
            'vendor_id',
            'carro',
            'make',
            'model',
            'chasis',
            'engine',
            'vin',
        ];
    }

    public function array(): array
    {
        return [
            [
                'M14x1.50RACK M12x1.50TIE 220LENGTH',
                'TIE-001',
                'LS-000001',
                '555',
                'Terminales',
                '5.35',
                '6.42',
                '10',
                'Terminal importado desde Lightspeed',
                'Distribuidora Nacional',
                'VEN-001',
                'TOYOTA YARIS P90 1NZFE NCP93L-BEMRK MTM',
                'TOYOTA',
                'YARIS',
                'P90',
                '1NZFE',
                '',
            ],
            [
                '10W30 cuarto mineral',
                'OIL-10W30-QT',
                'LS-000002',
                'Shell',
                'Aceite Motor',
                '6.00',
                '7.25',
                '4',
                'Aceite importado desde Lightspeed',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
            ],
        ];
    }
}
