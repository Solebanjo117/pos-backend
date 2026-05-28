<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Orden de Compra - {{ $purchaseOrder->po_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            color: #1f2937;
            padding: 18px;
        }

        .header {
            border-bottom: 3px solid #059669;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }

        .brand-row {
            width: 100%;
            display: table;
        }

        .brand-cell {
            display: table-cell;
            vertical-align: top;
        }

        .brand-name {
            font-size: 22px;
            font-weight: bold;
            color: #047857;
            margin-bottom: 3px;
        }

        .brand-meta {
            color: #6b7280;
            line-height: 1.45;
        }

        .document-box {
            text-align: right;
        }

        .document-title {
            font-size: 17px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 5px;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 999px;
            background: #ecfdf5;
            color: #047857;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .info-grid {
            display: table;
            width: 100%;
            margin-bottom: 14px;
            border: 1px solid #d1d5db;
        }

        .info-row {
            display: table-row;
        }

        .info-cell {
            display: table-cell;
            width: 50%;
            padding: 7px 8px;
            vertical-align: top;
            border-bottom: 1px solid #e5e7eb;
        }

        .info-row:last-child .info-cell {
            border-bottom: 0;
        }

        .info-label {
            font-size: 8px;
            font-weight: bold;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 2px;
        }

        .info-value {
            font-size: 10px;
            line-height: 1.45;
            color: #111827;
        }

        .worksheet-note {
            background-color: #fffbeb;
            border-left: 4px solid #f59e0b;
            padding: 9px 10px;
            margin: 12px 0;
            font-size: 10px;
            line-height: 1.45;
        }

        .section-title {
            font-size: 12px;
            font-weight: bold;
            color: #111827;
            margin: 16px 0 7px;
            padding-bottom: 4px;
            border-bottom: 1px solid #d1d5db;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }

        th {
            background-color: #f3f4f6;
            padding: 7px 6px;
            text-align: left;
            font-weight: bold;
            font-size: 8px;
            text-transform: uppercase;
            color: #374151;
            border: 1px solid #d1d5db;
        }

        td {
            padding: 7px 6px;
            border: 1px solid #e5e7eb;
            vertical-align: top;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .mono {
            font-family: DejaVu Sans Mono, Consolas, monospace;
            font-size: 9px;
        }

        .muted {
            color: #6b7280;
        }

        .product-name {
            font-weight: bold;
            color: #111827;
            line-height: 1.35;
        }

        .product-meta {
            margin-top: 2px;
            color: #6b7280;
            font-size: 8px;
            line-height: 1.35;
        }

        .totals-section {
            margin-top: 18px;
            margin-left: auto;
            width: 42%;
            border: 1px solid #d1d5db;
        }

        .total-row {
            display: table;
            width: 100%;
            border-bottom: 1px solid #e5e7eb;
        }

        .total-row:last-child {
            border-bottom: 0;
        }

        .total-label,
        .total-value {
            display: table-cell;
            padding: 7px 8px;
        }

        .total-value {
            text-align: right;
            font-family: DejaVu Sans Mono, Consolas, monospace;
        }

        .grand-total {
            background: #ecfdf5;
            font-weight: bold;
            font-size: 12px;
            color: #065f46;
        }

        .notes-section {
            clear: both;
            margin-top: 22px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
        }

        .summary-grid {
            clear: both;
            width: 100%;
            display: table;
            margin-top: 18px;
        }

        .summary-cell {
            display: table-cell;
            width: 50%;
            padding-right: 10px;
            vertical-align: top;
        }

        .summary-cell:last-child {
            padding-right: 0;
            padding-left: 10px;
        }

        .checkbox {
            display: inline-block;
            width: 13px;
            height: 13px;
            border: 1px solid #6b7280;
        }

        .signature-grid {
            margin-top: 35px;
            width: 100%;
            display: table;
        }

        .signature-cell {
            display: table-cell;
            width: 50%;
            padding: 0 18px;
            text-align: center;
        }

        .signature-line {
            border-top: 1px solid #111827;
            padding-top: 5px;
        }

        .footer {
            margin-top: 28px;
            text-align: center;
            font-size: 8px;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
        }
    </style>
</head>
<body>
    @php
        $isWorksheet = $mode === 'worksheet';
        $statusLabels = [
            'draft' => 'Borrador',
            'open' => 'Abierta',
            'sent' => 'Enviada',
            'partial' => 'Parcial',
            'received' => 'Recibida',
            'cancelled' => 'Cancelada',
        ];
        $supplier = $purchaseOrder->supplier;
        $warehouse = $purchaseOrder->warehouse;
        $itemColumnCount = ($isWorksheet ? 7 : 11) + ($supplier ? 0 : 1);
    @endphp

    <div class="header">
        <div class="brand-row">
            <div class="brand-cell">
                <div class="brand-name">{{ $store_name ?? 'HermeX POS' }}</div>
                <div class="brand-meta">
                    @if(! empty($store_tagline)){{ $store_tagline }}<br>@endif
                    @if(! empty($store_address)){{ $store_address }}<br>@endif
                    @if(! empty($store_phone))Tel. {{ $store_phone }}@endif
                    @if(! empty($store_email)){{ ! empty($store_phone) ? ' | ' : '' }}{{ $store_email }}@endif
                </div>
            </div>
            <div class="brand-cell document-box">
                <div class="document-title">{{ $isWorksheet ? 'HOJA DE PEDIDO' : 'ORDEN DE COMPRA' }}</div>
                <div class="status-badge">{{ $statusLabels[$purchaseOrder->status] ?? $purchaseOrder->status }}</div>
            </div>
        </div>
    </div>

    @if($isWorksheet)
        <div class="worksheet-note">
            <strong>Instrucciones:</strong> hoja de trabajo para validar el pedido antes de enviarlo o recibirlo.
            Marque los articulos revisados y anote cambios de cantidad, costo o proveedor en observaciones.
        </div>
    @endif

    <div class="info-grid">
        <div class="info-row">
            <div class="info-cell">
                <div class="info-label">Numero de orden</div>
                <div class="info-value"><strong>{{ $purchaseOrder->po_number }}</strong></div>
            </div>
            <div class="info-cell">
                <div class="info-label">Fecha de orden</div>
                <div class="info-value">{{ optional($purchaseOrder->order_date)->format('d/m/Y') ?? '-' }}</div>
            </div>
        </div>
        <div class="info-row">
            <div class="info-cell">
                <div class="info-label">Proveedor</div>
                <div class="info-value">
                    @if($supplier)
                        <strong>{{ $supplier->name }}</strong><br>
                        @if($supplier->contact_name)Contacto: {{ $supplier->contact_name }}<br>@endif
                        @if($supplier->phone)Tel. {{ $supplier->phone }}<br>@endif
                        @if($supplier->email){{ $supplier->email }}<br>@endif
                        @if($supplier->address){{ $supplier->address }}@endif
                    @else
                        <strong>OC diaria general</strong><br>
                        <span class="muted">Proveedor definido por articulo</span>
                    @endif
                </div>
            </div>
            <div class="info-cell">
                <div class="info-label">Almacen destino</div>
                <div class="info-value">{{ $warehouse?->name ?? '-' }}</div>
            </div>
        </div>
        <div class="info-row">
            <div class="info-cell">
                <div class="info-label">Factura proveedor</div>
                <div class="info-value">{{ $purchaseOrder->supplier_invoice_number ?: '-' }}</div>
            </div>
            <div class="info-cell">
                <div class="info-label">Creada por</div>
                <div class="info-value">{{ $purchaseOrder->user?->name ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="section-title">Detalle de articulos</div>

    <table>
        <thead>
            <tr>
                @if($isWorksheet)
                    <th style="width: 24px;" class="text-center">OK</th>
                @endif
                <th style="width: 28px;" class="text-center">#</th>
                <th style="width: 82px;">SKU base</th>
                <th style="width: 70px;">Marca</th>
                @if(! $supplier)
                    <th style="width: 95px;">Proveedor</th>
                @endif
                <th>Articulo</th>
                <th style="width: 45px;" class="text-right">QTY</th>
                @if($isWorksheet)
                    <th style="width: 120px;">Observaciones</th>
                @else
                    <th style="width: 70px;" class="text-right">Costo</th>
                    <th style="width: 65px;" class="text-right">ITBM</th>
                    <th style="width: 65px;" class="text-right">Misc</th>
                    <th style="width: 78px;" class="text-right">Total</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($purchaseOrder->items as $index => $item)
                @php
                    $product = $item->product;
                    $variant = $item->variant;
                    $brandName = $variant?->brand?->name;
                    $lineSubtotal = (float) ($item->subtotal ?? ((float) $item->quantity_ordered * (float) ($item->units_per_package ?? 1) * (float) $item->unit_cost));
                    $lineTaxAmount = (float) ($item->tax_amount ?? 0);
                    $lineMiscCost = (float) ($item->misc_cost ?? 0);
                    $lineTotal = (float) ($item->total ?? ($lineSubtotal + $lineTaxAmount + $lineMiscCost));
                @endphp
                <tr>
                    @if($isWorksheet)
                        <td class="text-center"><span class="checkbox"></span></td>
                    @endif
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td class="mono">{{ $product?->sku_base ?: '-' }}</td>
                    <td>{{ $brandName ?: '-' }}</td>
                    @if(! $supplier)
                        <td>{{ $item->supplier?->name ?? '-' }}</td>
                    @endif
                    <td>
                        <div class="product-name">{{ $product?->name ?? 'Articulo sin nombre' }}</div>
                        @if($item->notes)
                            <div class="product-meta">Nota: {{ $item->notes }}</div>
                        @endif
                    </td>
                    <td class="text-right mono">{{ number_format((float) $item->quantity_ordered, 0) }}</td>
                    @if($isWorksheet)
                        <td></td>
                    @else
                        <td class="text-right mono">${{ number_format((float) $item->unit_cost, 2) }}</td>
                        <td class="text-right mono">${{ number_format($lineTaxAmount, 2) }}</td>
                        <td class="text-right mono">${{ number_format($lineMiscCost, 2) }}</td>
                        <td class="text-right mono">${{ number_format($lineTotal, 2) }}</td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $itemColumnCount }}" class="text-center muted">No hay articulos en esta orden.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if(! $isWorksheet)
        <div class="totals-section">
            <div class="total-row">
                <div class="total-label">Subtotal</div>
                <div class="total-value">${{ number_format((float) $purchaseOrder->subtotal, 2) }}</div>
            </div>
            @if((float) $purchaseOrder->tax_amount > 0)
                <div class="total-row">
                    <div class="total-label">ITBM</div>
                    <div class="total-value">${{ number_format((float) $purchaseOrder->tax_amount, 2) }}</div>
                </div>
            @endif
            @php $miscSum = $purchaseOrder->items->sum(fn($i) => (float) ($i->misc_cost ?? 0)); @endphp
            @if($miscSum > 0)
                <div class="total-row">
                    <div class="total-label">Misc</div>
                    <div class="total-value">${{ number_format($miscSum, 2) }}</div>
                </div>
            @endif
            @if((float) $purchaseOrder->shipping_cost > 0)
                <div class="total-row">
                    <div class="total-label">Envio</div>
                    <div class="total-value">${{ number_format((float) $purchaseOrder->shipping_cost, 2) }}</div>
                </div>
            @endif
            <div class="total-row grand-total">
                <div class="total-label">TOTAL</div>
                <div class="total-value">${{ number_format((float) $purchaseOrder->total, 2) }}</div>
            </div>
        </div>

        @php
            $amountPaid = (float) $purchaseOrder->amount_paid;
            $amountDue = (float) $purchaseOrder->amount_due;
            $receivedPackages = $purchaseOrder->items->sum(fn($item) => (int) ($item->effective_received_quantity ?? $item->quantity_received ?? 0));
            $orderedPackages = $purchaseOrder->items->sum(fn($item) => (int) $item->quantity_ordered);
        @endphp

        <div class="summary-grid">
            <div class="summary-cell">
                <div class="section-title">Resumen de pago</div>
                <table>
                    <tbody>
                        <tr>
                            <td>Total OC</td>
                            <td class="text-right mono">${{ number_format((float) $purchaseOrder->total, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Pagado</td>
                            <td class="text-right mono">${{ number_format($amountPaid, 2) }}</td>
                        </tr>
                        <tr>
                            <td><strong>Saldo AP</strong></td>
                            <td class="text-right mono"><strong>${{ number_format($amountDue, 2) }}</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="summary-cell">
                <div class="section-title">Resumen de recepcion</div>
                <table>
                    <tbody>
                        <tr>
                            <td>Paquetes ordenados</td>
                            <td class="text-right mono">{{ number_format($orderedPackages, 0) }}</td>
                        </tr>
                        <tr>
                            <td>Paquetes recibidos</td>
                            <td class="text-right mono">{{ number_format($receivedPackages, 0) }}</td>
                        </tr>
                        <tr>
                            <td><strong>Pendientes</strong></td>
                            <td class="text-right mono"><strong>{{ number_format(max(0, $orderedPackages - $receivedPackages), 0) }}</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="notes-section">
        @if($purchaseOrder->notes)
            <div class="info-label">Notas</div>
            <div class="info-value">{{ $purchaseOrder->notes }}</div>
        @endif

        @if($isWorksheet)
            <div style="margin-top: 22px;">
                <div class="info-label">Notas adicionales / cambios</div>
                <div style="border: 1px solid #d1d5db; height: 58px; margin-top: 6px;"></div>
            </div>

            <div class="signature-grid">
                <div class="signature-cell">
                    <div class="signature-line">
                        <strong>Solicitado por</strong><br>
                        <small>Nombre y firma</small>
                    </div>
                </div>
                <div class="signature-cell">
                    <div class="signature-line">
                        <strong>Procesado por</strong><br>
                        <small>Nombre y firma</small>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <div class="footer">
        Documento generado el {{ now()->format('d/m/Y H:i') }} - {{ $isWorksheet ? 'Hoja de pedido' : 'Orden de compra' }}
    </div>
</body>
</html>
