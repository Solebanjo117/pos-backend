<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class VehicleImportTemplate implements FromArray, ShouldAutoSize, WithHeadings
{
    public function headings(): array
    {
        return ['MAKE', 'MODEL', 'YEAR', 'CODE', 'ENGINE', 'VIN'];
    }

    public function array(): array
    {
        return [
            ['TOYOTA', 'YARIS',   '2000-2005', 'P10',  '1NZFE',  'MR2BT9F3101124478'],
            ['TOYOTA', 'COROLLA', '2018-2023', 'E210', '2ZRFE',  ''],
            ['HONDA',  'CIVIC',   '2016-2021', 'FC5',  'L15B7',  ''],
        ];
    }
}
