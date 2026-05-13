<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CategoryImportTemplate implements FromArray, ShouldAutoSize, WithHeadings
{
    public function headings(): array
    {
        return [
            'nombre',
            'slug',
            'categoria_padre',
            'descripcion',
            'margen_default',
            'formula_precio',
            'aplica_itbm',
            'itbm_porcentaje',
            'prioridad',
            'precio_minimo_sugerido',
            'upsale_path',
            'atributos_default',
        ];
    }

    public function array(): array
    {
        return [
            ['Frenos', 'frenos', 'Auto', 'Sistema de frenado', 30, 'costo * 1.3', 'si', 7, 10, '', 'frenos/pastillas', 'posicion:requerido,largo:15cm'],
            ['Filtros', 'filtros', 'Auto', 'Filtros de todo tipo', 25, 'costo * 1.25', 'si', 7, 20, '', 'motor/filtros', 'tipo,medida'],
        ];
    }
}
