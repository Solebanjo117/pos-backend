<?php

namespace App\Services;

use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;

class PdfExportService
{
    /**
     * Gather all store settings for PDF headers.
     */
    private function storeData(): array
    {
        return [
            'store_name' => Setting::get('store_name', 'HermeX POS'),
            'store_tagline' => Setting::get('store_tagline', ''),
            'store_logo_url' => Setting::get('store_logo_url', null),
            'store_owner_name' => Setting::get('store_owner_name', ''),
            'store_phone' => Setting::get('store_phone', ''),
            'store_email' => Setting::get('store_email', ''),
            'store_address' => Setting::get('store_address', ''),
            'store_tax_id' => Setting::get('store_tax_id', ''),
            'store_ticket_footer' => Setting::get('store_ticket_footer', '¡Gracias por su compra!'),
        ];
    }

    /**
     * Export stock levels report to PDF
     */
    public function exportStockLevels(array $reportData): string
    {
        $pdf = Pdf::loadView('pdf.stock-levels', array_merge([
            'report' => $reportData,
            'generatedAt' => now()->format('d/m/Y H:i'),
        ], $this->storeData()));

        $pdf->setPaper('a4', 'landscape');

        return $pdf->output();
    }

    /**
     * Export movement history report to PDF
     */
    public function exportMovementHistory(array $reportData): string
    {
        $pdf = Pdf::loadView('pdf.movement-history', array_merge([
            'report' => $reportData,
            'generatedAt' => now()->format('d/m/Y H:i'),
        ], $this->storeData()));

        $pdf->setPaper('a4', 'landscape');

        return $pdf->output();
    }

    public function exportSalesByClient(array $reportData): string
    {
        $pdf = Pdf::loadHTML($this->salesByClientHtml($reportData));
        $pdf->setPaper('a4', 'landscape');

        return $pdf->output();
    }

    private function salesByClientHtml(array $reportData): string
    {
        $store = $this->storeData();
        $summary = $reportData['summary'] ?? [];
        $rows = $reportData['data'] ?? [];
        $generatedAt = now()->format('d/m/Y H:i');
        $money = fn (mixed $value): string => '$'.number_format((float) $value, 2);
        $bodyRows = collect($rows)->map(function (array $row) use ($money): string {
            return '<tr>'
                .'<td>'.e($row['customer_code'] ?? '-').'</td>'
                .'<td>'.e($row['customer_name'] ?? '-').'</td>'
                .'<td>'.e($row['referred_by_customer_name'] ?? '-').'</td>'
                .'<td class="num">'.(int) ($row['orders_count'] ?? 0).'</td>'
                .'<td class="num">'.$money($row['total_revenue'] ?? 0).'</td>'
                .'<td class="num">'.$money($row['total_paid'] ?? 0).'</td>'
                .'<td class="num">'.$money($row['pending_amount'] ?? 0).'</td>'
                .'<td>'.e($row['last_payment_date'] ?? '-').'</td>'
                .'<td>'.e($row['dominant_payment_method_label'] ?? '-').'</td>'
                .'<td>'.e($row['status_label'] ?? '-').'</td>'
                .'</tr>';
        })->implode('');

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,Arial,sans-serif;color:#111827;font-size:11px}
            h1{font-size:20px;margin:0 0 4px}
            .muted{color:#6b7280}
            .summary{display:table;width:100%;margin:16px 0;border-spacing:8px 0}
            .card{display:table-cell;border:1px solid #e5e7eb;border-radius:6px;padding:10px}
            .label{font-size:9px;text-transform:uppercase;color:#6b7280;font-weight:bold}
            .value{font-size:15px;font-weight:bold;margin-top:4px}
            table{width:100%;border-collapse:collapse;margin-top:12px}
            th{background:#4f46e5;color:#fff;text-align:left;padding:7px;font-size:9px;text-transform:uppercase}
            td{border-bottom:1px solid #e5e7eb;padding:6px}
            .num{text-align:right;font-variant-numeric:tabular-nums}
        </style></head><body>'
            .'<h1>Ventas por Cliente</h1>'
            .'<div class="muted">'.e($store['store_name']).' · Generado '.$generatedAt.'</div>'
            .'<div class="summary">'
            .'<div class="card"><div class="label">Total Facturado</div><div class="value">'.$money($summary['total_revenue'] ?? 0).'</div></div>'
            .'<div class="card"><div class="label">Facturas</div><div class="value">'.(int) ($summary['orders_count'] ?? 0).'</div></div>'
            .'<div class="card"><div class="label">Ticket Promedio</div><div class="value">'.$money($summary['average_ticket'] ?? 0).'</div></div>'
            .'<div class="card"><div class="label">Pendiente</div><div class="value">'.$money($summary['pending_amount'] ?? 0).'</div></div>'
            .'</div>'
            .'<table><thead><tr><th>Codigo</th><th>Cliente</th><th>Referido</th><th>Facturas</th><th>Total</th><th>Cobrado</th><th>Pendiente</th><th>Ultimo Pago</th><th>Metodo</th><th>Estado</th></tr></thead><tbody>'
            .$bodyRows
            .'</tbody></table></body></html>';
    }

    /**
     * Export Purchase Order to PDF
     */
    public function exportPurchaseOrder($purchaseOrder, string $mode = 'standard')
    {
        $pdf = Pdf::loadView('pdf.purchase-order', array_merge([
            'purchaseOrder' => $purchaseOrder,
            'mode' => $mode,
        ], $this->storeData()));

        $pdf->setPaper('a4', 'portrait');

        return $pdf->output();
    }
}
