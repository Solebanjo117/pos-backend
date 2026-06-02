<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SalesByClientExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private array $reportData) {}

    public function array(): array
    {
        return collect($this->reportData['data'] ?? [])
            ->map(fn (array $row): array => [
                $row['customer_code'] ?? '-',
                $row['customer_name'] ?? '-',
                $row['referred_by_customer_name'] ?? '-',
                $row['orders_count'] ?? 0,
                number_format((float) ($row['total_revenue'] ?? 0), 2),
                number_format((float) ($row['total_paid'] ?? 0), 2),
                number_format((float) ($row['pending_amount'] ?? 0), 2),
                $row['last_payment_date'] ?? '-',
                number_format((float) ($row['last_payment_amount'] ?? 0), 2),
                $row['dominant_payment_method_label'] ?? '-',
                $row['status_label'] ?? '-',
            ])
            ->all();
    }

    public function headings(): array
    {
        return [
            'Codigo',
            'Cliente',
            'Mecanico / Referido',
            'Facturas',
            'Total Facturado',
            'Total Cobrado',
            'Pendiente',
            'Fecha Ultimo Pago',
            'Monto Ultimo Pago',
            'Metodo Dominante',
            'Estado',
        ];
    }

    public function styles(Worksheet $sheet): array
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
        return 'Ventas por Cliente';
    }
}
