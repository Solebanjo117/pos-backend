<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CustomerImportTemplate implements FromArray, ShouldAutoSize, WithHeadings
{
    public function headings(): array
    {
        return ['codigo', 'nombre', 'empresa', 'tipo', 'ruc', 'telefono', 'email', 'direccion', 'notas', 'marca_vehiculo', 'modelo_vehiculo', 'motor_vehiculo', 'anio', 'vin', 'placa'];
    }

    public function array(): array
    {
        return [['', 'Ana Pérez', '', 'Individual', '', '6000-0000', 'ana@example.com', 'Panamá', '', '', '', '', '', '', '']];
    }
}
