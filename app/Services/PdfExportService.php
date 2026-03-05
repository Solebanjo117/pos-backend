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
            'store_name'          => Setting::get('store_name', 'HermeX POS'),
            'store_tagline'       => Setting::get('store_tagline', ''),
            'store_logo_url'      => Setting::get('store_logo_url', null),
            'store_owner_name'    => Setting::get('store_owner_name', ''),
            'store_phone'         => Setting::get('store_phone', ''),
            'store_email'         => Setting::get('store_email', ''),
            'store_address'       => Setting::get('store_address', ''),
            'store_tax_id'        => Setting::get('store_tax_id', ''),
            'store_ticket_footer' => Setting::get('store_ticket_footer', '¡Gracias por su compra!'),
        ];
    }

    /**
     * Export stock levels report to PDF
     */
    public function exportStockLevels(array $reportData): string
    {
        $pdf = Pdf::loadView('pdf.stock-levels', array_merge([
            'report'      => $reportData,
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
            'report'      => $reportData,
            'generatedAt' => now()->format('d/m/Y H:i'),
        ], $this->storeData()));

        $pdf->setPaper('a4', 'landscape');

        return $pdf->output();
    }

    /**
     * Export Purchase Order to PDF
     */
    public function exportPurchaseOrder($purchaseOrder, string $mode = 'standard')
    {
        $pdf = Pdf::loadView('pdf.purchase-order', array_merge([
            'purchaseOrder' => $purchaseOrder,
            'mode'          => $mode,
        ], $this->storeData()));

        $pdf->setPaper('a4', 'portrait');

        return $pdf->output();
    }
}
