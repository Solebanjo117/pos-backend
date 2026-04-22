<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MovementHistoryExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    protected array $reportData;

    public function __construct(array $reportData)
    {
        $this->reportData = $reportData;
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->reportData['movements'] as $movement) {
            $rows[] = [
                $movement['date'],
                $movement['product_sku'],
                $movement['product_name'],
                $movement['warehouse'],
                $movement['destination_warehouse'] ?? '-',
                $this->translateType($movement['movement_type']),
                $movement['quantity'],
                $movement['user'] ?? '-',
            ];
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            'Fecha',
            'SKU',
            'Producto',
            'Almacén Origen',
            'Almacén Destino',
            'Tipo de Movimiento',
            'Cantidad',
            'Usuario',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4F46E5'],
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Histórico de Movimientos';
    }

    protected function translateType(string $type): string
    {
        return match ($type) {
            'in' => 'Entrada',
            'out' => 'Salida',
            'transfer' => 'Transferencia',
            default => $type,
        };
    }
}
