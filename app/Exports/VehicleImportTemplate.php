<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class VehicleImportTemplate implements FromArray, ShouldAutoSize, WithHeadings
{
    public function headings(): array
    {
        return ['MAKE', 'MODEL', 'YEAR', 'GENERATION', 'CODE', 'ENGINE', 'DISPLACEMENT', 'FUEL_TYPE', 'ASPIRATION', 'VIN'];
    }

    public function array(): array
    {
        return [
            ['TOYOTA', 'YARIS', '2000-2005', 'P10', 'P10', '1NZFE', '1.5L', 'Gasoline', 'Naturally Aspirated', 'MR2BT9F3101124478'],
            ['TOYOTA', 'YARIS', '2006-2013', 'P90', 'P90', '2NZFE', '1.3L', 'Gasoline', 'Naturally Aspirated', "JTDKW923900008116\nJTDKW923705134932"],
            ['TOYOTA', 'YARIS', '2006-2013', '', '', '1NZFE', '1.5L', 'Gasoline', '', 'JTDBT933X01109408'],
            ['TOYOTA', 'COROLLA', '2018-2023', 'E210', 'E210', '2ZRFE', '1.8L', 'Gasoline', '', ''],
            ['HONDA', 'CIVIC', '2016-2021', 'FC', 'FC5', 'L15B7', '1.5L', 'Gasoline', 'Turbo', ''],
        ];
    }
}
