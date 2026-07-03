<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventoryItemsExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private array $rows,
        private bool $template = false,
    ) {}

    public function array(): array
    {
        return collect($this->rows)
            ->map(fn (array $row): array => $this->template ? $this->templateRow($row) : $this->backupRow($row))
            ->all();
    }

    public function headings(): array
    {
        if ($this->template) {
            return [
                'system_id',
                'sku',
                'nombre',
                'marca',
                'categoria',
                'cantidad',
                'costo',
                'precio',
                'almacen',
                'ubicacion',
                'upc',
                'estado',
            ];
        }

        return [
            'System ID producto',
            'System ID variante',
            'SKU producto',
            'SKU variante',
            'UPC',
            'Producto',
            'Marca',
            'Categoria',
            'Estado',
            'Almacen',
            'Codigo almacen',
            'Ubicacion',
            'Stock disponible',
            'Stock reservado',
            'Punto reorden',
            'Cantidad reorden',
            'Costo FIFO',
            'Precio actual',
            'Valor inventario',
            'Proveedor preferido',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '0F766E'],
                ],
            ],
        ];
    }

    public function title(): string
    {
        return $this->template ? 'Plantilla Inventario' : 'Inventario Items';
    }

    private function backupRow(array $row): array
    {
        return [
            $row['product_system_id'] ?? null,
            $row['variant_system_id'] ?? null,
            $row['product_sku'] ?? null,
            $row['variant_sku'] ?? null,
            $row['upc'] ?? null,
            $row['product_name'] ?? null,
            $row['brand'] ?? null,
            $row['category'] ?? null,
            $row['status'] ?? null,
            $row['warehouse'] ?? null,
            $row['warehouse_code'] ?? null,
            $row['location'] ?? null,
            $row['quantity_available'] ?? 0,
            $row['quantity_reserved'] ?? 0,
            $row['reorder_point'] ?? 0,
            $row['reorder_quantity'] ?? 0,
            $row['fifo_cost'] ?? 0,
            $row['price'] ?? 0,
            $row['stock_value'] ?? 0,
            $row['preferred_supplier'] ?? null,
        ];
    }

    private function templateRow(array $row): array
    {
        return [
            $row['product_system_id'] ?? null,
            $row['variant_sku'] ?? $row['product_sku'] ?? null,
            $row['product_name'] ?? null,
            $row['brand'] ?? null,
            $row['category'] ?? null,
            $row['quantity_available'] ?? 0,
            $row['fifo_cost'] ?? 0,
            $row['price'] ?? 0,
            $row['warehouse_code'] ?? $row['warehouse'] ?? null,
            $row['location'] ?? null,
            $row['upc'] ?? null,
            $row['status'] ?? null,
        ];
    }
}
