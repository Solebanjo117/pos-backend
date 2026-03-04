<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\View;

class PdfExportService
{
    /**
     * Export stock levels report to PDF
     */
    public function exportStockLevels(array $reportData): string
    {
        $pdf = Pdf::loadView('pdf.stock-levels', [
            'report' => $reportData,
            'generatedAt' => now()->format('d/m/Y H:i'),
        ]);

        $pdf->setPaper('a4', 'landscape');
        
        return $pdf->output();
    }

    /**
     * Export movement history report to PDF
     */
    public function exportMovementHistory(array $reportData): string
    {
        $pdf = Pdf::loadView('pdf.movement-history', [
            'report' => $reportData,
            'generatedAt' => now()->format('d/m/Y H:i'),
        ]);

        $pdf->setPaper('a4', 'landscape');
        
        return $pdf->output();
    }

    /**
     * Export Purchase Order to PDF
     */
    public function exportPurchaseOrder($purchaseOrder, string $mode = 'standard')
    {
        $pdf = Pdf::loadView('pdf.purchase-order', [
            'purchaseOrder' => $purchaseOrder,
            'mode' => $mode,
        ]);

        $pdf->setPaper('a4', 'portrait');

        return $pdf->output();
    }
}
