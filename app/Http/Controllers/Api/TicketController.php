<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Picqer\Barcode\BarcodeGeneratorPNG;

class TicketController extends Controller
{
    /**
     * GET /api/sales-orders/{id}/ticket
     * Generate a thermal ticket PDF for a sales order.
     */
    public function ticket(Request $request, int $id)
    {
        $type = $request->query('type', 'internal'); // 'internal' or 'client'

        $order = SalesOrder::with([
            'items.product',
            'items.variant',
            'items.warehouse',
            'customer',
            'user',
            'payments',
        ])->findOrFail($id);

        // Business info — could come from a settings table in the future
        $businessName    = config('app.business_name', 'Mi Negocio');
        $businessAddress = config('app.business_address', '');
        $businessPhone   = config('app.business_phone', '');
        $businessRfc     = config('app.business_rfc', '');

        // Generate barcode SVG
        $barcodeSvg = $this->generateBarcodeSvg($order->order_number);
        $barcodeValue = preg_replace('/\D/', '', $order->order_number);

        $pdf = Pdf::loadView('tickets.sale', [
            'order'           => $order,
            'businessName'    => $businessName,
            'businessAddress' => $businessAddress,
            'businessPhone'   => $businessPhone,
            'businessRfc'     => $businessRfc,
            'type'            => $type,
            'barcodeSvg'      => $barcodeSvg,
            'barcodeValue'    => $barcodeValue,
        ]);

        // 80mm ≈ 226.77pt, height auto (long receipt)
        $pdf->setPaper([0, 0, 226.77, 800], 'portrait');
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('defaultFont', 'Courier');

        return $pdf->stream("ticket-{$order->order_number}.pdf");
    }

    /**
     * GET /api/sales-orders/{id}/note
     * Generate an A4 credit note / invoice for the order.
     */
    public function note(int $id)
    {
        $order = SalesOrder::with([
            'items.product',
            'items.variant',
            'items.warehouse',
            'customer',
            'user',
            'payments',
        ])->findOrFail($id);

        $businessName    = config('app.business_name', 'Mi Negocio');
        $businessAddress = config('app.business_address', '');
        $businessPhone   = config('app.business_phone', '');
        $businessRfc     = config('app.business_rfc', '');

        // Generate barcode SVG
        $barcodeSvg = $this->generateBarcodeSvg($order->order_number);
        $barcodeValue = preg_replace('/\D/', '', $order->order_number);

        $pdf = Pdf::loadView('tickets.sale', [
            'order'           => $order,
            'businessName'    => $businessName,
            'businessAddress' => $businessAddress,
            'businessPhone'   => $businessPhone,
            'businessRfc'     => $businessRfc,
            'barcodeSvg'      => $barcodeSvg,
            'barcodeValue'    => $barcodeValue,
        ]);

        $pdf->setPaper('letter', 'portrait');

        return $pdf->stream("nota-{$order->order_number}.pdf");
    }

    /**
     * GET /api/sales-orders/{id}/proforma
     * Generate a half-letter landscape proforma for the order.
     */
    public function proforma(Request $request, int $id)
    {
        $type = $request->query('type', 'internal'); // 'internal' or 'client'

        $order = SalesOrder::with([
            'items.product.category',
            'items.product.variants.brand',
            'items.product.variants.pricing',
            'items.product.vins',
            'items.variant',
            'items.warehouse',
            'customer',
            'user',
            'payments',
        ])->findOrFail($id);

        // Business info
        $businessName    = config('app.business_name', 'Mi Negocio');
        $businessAddress = config('app.business_address', '');
        $businessPhone   = config('app.business_phone', '');
        $businessEmail   = config('app.business_email', '');
        $businessRfc     = config('app.business_rfc', '');

        // Calculate dominant tax percent for display
        $taxPercent = 7;
        if ($order->items->count() > 0) {
            $firstTax = $order->items->first()->tax_percent ?? 7;
            $taxPercent = $firstTax;
        }

        // Generate barcode SVG
        $barcodeSvg = $this->generateBarcodeSvg($order->order_number);
        $barcodeValue = preg_replace('/\D/', '', $order->order_number);

        $pdf = Pdf::loadView('tickets.proforma', [
            'order'           => $order,
            'businessName'    => $businessName,
            'businessAddress' => $businessAddress,
            'businessPhone'   => $businessPhone,
            'businessEmail'   => $businessEmail,
            'businessRfc'     => $businessRfc,
            'type'            => $type,
            'taxPercent'      => $taxPercent,
            'barcodeSvg'      => $barcodeSvg,
            'barcodeValue'    => $barcodeValue,
        ]);

        // Half-letter landscape: 8.5" x 5.5" = 612pt x 396pt
        $pdf->setPaper([0, 0, 612, 396], 'landscape');
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('defaultFont', 'Helvetica');

        return $pdf->stream("proforma-{$order->order_number}.pdf");
    }

    /**
     * Generate a PNG barcode as a base64 data URI from the order number.
     * Extracts digits only (e.g. ORD-20260303-0001 → 202603030001).
     */
    private function generateBarcodeSvg(string $orderNumber): string
    {
        $numericValue = preg_replace('/\D/', '', $orderNumber);
        if (empty($numericValue)) {
            $numericValue = $orderNumber;
        }

        $generator = new BarcodeGeneratorPNG();
        $png = $generator->getBarcode($numericValue, $generator::TYPE_CODE_128, 2, 50);
        return 'data:image/png;base64,' . base64_encode($png);
    }
}
