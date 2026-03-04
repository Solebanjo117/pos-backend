<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;

class StockLevelsExport implements FromArray, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
{
    protected array $reportData;

    public function __construct(array $reportData)
    {
        $this->reportData = $reportData;
    }

    public function array(): array
    {
        $rows = [];
        
        foreach ($this->reportData['products'] as $product) {
            $rows[] = [
                $product['sku'],
                $product['name'],
                $product['brand'] ?? '-',
                $product['warehouse'],
                $product['current_stock'],
                $product['reorder_point'],
                $product['target_level'],
                $this->translateStatus($product['status']),
                number_format($product['stock_value'], 2),
            ];
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            'SKU',
            'Producto',
            'Marca',
            'Almacén',
            'Stock Actual',
            'Punto de Reorden',
            'Nivel Objetivo',
            'Estado',
            'Valor ($)',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4F46E5'] // indigo-600
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Niveles de Stock';
    }

    protected function translateStatus(string $status): string
    {
        return match($status) {
            'critical' => 'Crítico',
            'low' => 'Bajo',
            'normal' => 'Normal',
            'optimal' => 'Óptimo',
            default => $status,
        };
    }
}
