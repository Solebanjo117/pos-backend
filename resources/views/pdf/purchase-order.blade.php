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
            font-family: 'Arial', sans-serif;
            font-size: 11px;
            color: #333;
            padding: 20px;
        }
        .header {
            border-bottom: 3px solid #4F46E5;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #4F46E5;
            margin-bottom: 5px;
        }
        .document-title {
            font-size: 18px;
            font-weight: bold;
            color: #1F2937;
        }
        .info-grid {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        .info-row {
            display: table-row;
        }
        .info-cell {
            display: table-cell;
            padding: 8px;
            vertical-align: top;
            width: 50%;
        }
        .info-label {
            font-weight: bold;
            color: #6B7280;
            font-size: 10px;
            text-transform: uppercase;
            margin-bottom: 3px;
        }
        .info-value {
            font-size: 12px;
            color: #1F2937;
        }
        .section-title {
            font-size: 14px;
            font-weight: bold;
            color: #1F2937;
            margin: 20px 0 10px 0;
            padding-bottom: 5px;
            border-bottom: 2px solid #E5E7EB;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th {
            background-color: #F3F4F6;
            padding: 10px 8px;
            text-align: left;
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            color: #374151;
            border-bottom: 2px solid #D1D5DB;
        }
        td {
            padding: 8px;
            border-bottom: 1px solid #E5E7EB;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .totals-section {
            margin-top: 30px;
            float: right;
            width: 40%;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #E5E7EB;
        }
        .total-row.grand-total {
            font-weight: bold;
            font-size: 14px;
            border-top: 2px solid #4F46E5;
            border-bottom: 2px solid #4F46E5;
            margin-top: 5px;
        }
        .notes-section {
            clear: both;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #E5E7EB;
        }
        .checkbox {
            display: inline-block;
            width: 15px;
            height: 15px;
            border: 2px solid #9CA3AF;
            margin-right: 5px;
            vertical-align: middle;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-draft {
            background-color: #F3F4F6;
            color: #6B7280;
        }
        .worksheet-note {
            background-color: #FEF3C7;
            border-left: 4px solid #F59E0B;
            padding: 12px;
            margin: 20px 0;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="company-name">AutoParts POS</div>
        <div class="document-title">
            {{ $mode === 'worksheet' ? 'HOJA DE PEDIDO' : 'ORDEN DE COMPRA' }}
            <span class="status-badge status-{{ $purchaseOrder->status }}">{{ strtoupper($purchaseOrder->status) }}</span>
        </div>
    </div>

    @if($mode === 'worksheet')
    <div class="worksheet-note">
        <strong>📋 Instrucciones:</strong> Esta es una hoja de trabajo para realizar el pedido manualmente.
        Marque con ✓ los items verificados y anote cualquier cambio de cantidad o precio en las observaciones.
    </div>
    @endif

    <div class="info-grid">
        <div class="info-row">
            <div class="info-cell">
                <div class="info-label">Nº de Orden</div>
                <div class="info-value">{{ $purchaseOrder->po_number }}</div>
            </div>
            <div class="info-cell">
                <div class="info-label">Fecha</div>
                <div class="info-value">{{ $purchaseOrder->order_date->format('d/m/Y') }}</div>
            </div>
        </div>
        <div class="info-row">
            <div class="info-cell">
                <div class="info-label">Proveedor</div>
                <div class="info-value">
                    <strong>{{ $purchaseOrder->supplier->name }}</strong><br>
                    {{ $purchaseOrder->supplier->email }}<br>
                    {{ $purchaseOrder->supplier->phone }}
                </div>
            </div>
            <div class="info-cell">
                <div class="info-label">Almacén Destino</div>
                <div class="info-value">{{ $purchaseOrder->warehouse->name }}</div>
            </div>
        </div>
        @if($purchaseOrder->expected_date)
        <div class="info-row">
            <div class="info-cell">
                <div class="info-label">Fecha Esperada</div>
                <div class="info-value">{{ $purchaseOrder->expected_date->format('d/m/Y') }}</div>
            </div>
            <div class="info-cell"></div>
        </div>
        @endif
    </div>

    <div class="section-title">Detalle de Productos</div>

    <table>
        <thead>
            <tr>
                @if($mode === 'worksheet')
                <th style="width: 30px;">✓</th>
                @endif
                <th style="width: 60px;">#</th>
                <th style="width: 100px;">SKU</th>
                <th>Producto</th>
                <th class="text-center" style="width: 80px;">Cantidad</th>
                @if($mode === 'standard')
                <th class="text-right" style="width: 90px;">Costo Unit.</th>
                <th class="text-right" style="width: 100px;">Subtotal</th>
                @else
                <th style="width: 150px;">Observaciones</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($purchaseOrder->items as $index => $item)
            <tr>
                @if($mode === 'worksheet')
                <td class="text-center"><span class="checkbox"></span></td>
                @endif
                <td>{{ $index + 1 }}</td>
                <td>{{ $item->product->sku }}</td>
                <td>{{ $item->product->name }}</td>
                <td class="text-center">{{ $item->quantity_ordered }}</td>
                @if($mode === 'standard')
                <td class="text-right">${{ number_format($item->unit_cost, 2) }}</td>
                <td class="text-right">${{ number_format($item->subtotal, 2) }}</td>
                @else
                <td style="border-bottom: 1px solid #ccc;"></td>
                @endif
            </tr>
            @endforeach
        </tbody>
    </table>

    @if($mode === 'standard')
    <div class="totals-section">
        <div class="total-row">
            <span>Subtotal:</span>
            <span>${{ number_format($purchaseOrder->subtotal, 2) }}</span>
        </div>
        @if($purchaseOrder->tax_amount > 0)
        <div class="total-row">
            <span>Impuesto:</span>
            <span>${{ number_format($purchaseOrder->tax_amount, 2) }}</span>
        </div>
        @endif
        @if($purchaseOrder->shipping_cost > 0)
        <div class="total-row">
            <span>Envío:</span>
            <span>${{ number_format($purchaseOrder->shipping_cost, 2) }}</span>
        </div>
        @endif
        <div class="total-row grand-total">
            <span>TOTAL:</span>
            <span>${{ number_format($purchaseOrder->total, 2) }}</span>
        </div>
    </div>
    @endif

    <div class="notes-section" style="clear: both;">
        @if($purchaseOrder->notes)
        <div class="info-label">Notas</div>
        <div class="info-value">{{ $purchaseOrder->notes }}</div>
        @endif

        @if($mode === 'worksheet')
        <div style="margin-top: 30px;">
            <div class="info-label">Notas Adicionales / Cambios</div>
            <div style="border-bottom: 1px solid #ccc; margin: 10px 0; height: 60px;"></div>
        </div>

        <div style="margin-top: 40px; display: flex; justify-content: space-between;">
            <div style="width: 45%;">
                <div style="border-top: 2px solid #333; padding-top: 5px; text-align: center;">
                    <strong>Solicitado por</strong><br>
                    <small>Nombre y Firma</small>
                </div>
            </div>
            <div style="width: 45%;">
                <div style="border-top: 2px solid #333; padding-top: 5px; text-align: center;">
                    <strong>Procesado por</strong><br>
                    <small>Nombre y Firma</small>
                </div>
            </div>
        </div>
        @endif
    </div>

    <div style="margin-top: 40px; text-align: center; font-size: 9px; color: #9CA3AF; border-top: 1px solid #E5E7EB; padding-top: 10px;">
        Documento generado el {{ now()->format('d/m/Y H:i') }} - {{ $mode === 'worksheet' ? 'Hoja de Trabajo' : 'Documento oficial' }}
    </div>
</body>
</html>
