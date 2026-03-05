<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        /* Thermal ticket – 58mm / 80mm width */
        @page {
            margin: 2mm;
            size: 80mm auto;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', monospace;
            font-size: 11px;
            line-height: 1.3;
            width: 76mm;
            color: #000;
        }
        .center { text-align: center; }
        .right  { text-align: right; }
        .bold   { font-weight: bold; }
        .divider {
            border-top: 1px dashed #000;
            margin: 3px 0;
        }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1px 0; }
        .item-name { max-width: 42mm; word-wrap: break-word; }
        .total-row td { font-weight: bold; font-size: 13px; padding-top: 3px; }
        h1 { font-size: 16px; margin: 2px 0; }
        h2 { font-size: 12px; margin: 2px 0; }
        .small { font-size: 9px; color: #555; }
        .barcode { font-family: 'Code39', 'Courier New', monospace; font-size: 24px; letter-spacing: 2px; text-align: center; }
        .barcode-text { font-size: 10px; margin-top: -2px; letter-spacing: 4px; text-align: center; }
    </style>
</head>
<body>
    {{-- ===== HEADER ===== --}}
    <div class="center">
        <h1>{{ $businessName ?? 'Mi Negocio' }}</h1>
        @if(!empty($businessAddress))
            <div class="small">{{ $businessAddress }}</div>
        @endif
        @if(!empty($businessPhone))
            <div class="small">Tel: {{ $businessPhone }}</div>
        @endif
        @if(!empty($businessRfc))
            <div class="small">RFC: {{ $businessRfc }}</div>
        @endif
    </div>

    <div class="divider"></div>

    {{-- ===== ORDER INFO ===== --}}
    <table>
        <tr>
            <td class="bold">Ticket:</td>
            <td class="right">{{ $order->order_number }}</td>
        </tr>
        <tr>
            <td>Fecha:</td>
            <td class="right">{{ $order->created_at->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <td>Cajero:</td>
            <td class="right">{{ $order->user->name ?? 'N/A' }}</td>
        </tr>
        @if($order->customer)
        <tr>
            <td>Cliente:</td>
            <td class="right">{{ $order->customer->name }}</td>
        </tr>
        @endif
    </table>

    <div class="divider"></div>

    {{-- ===== ITEMS ===== --}}
    <table>
        <thead>
            <tr style="border-bottom: 1px solid #000;">
                <td class="bold" style="width: 50%;">Producto</td>
                <td class="bold center" style="width: 10%;">Cant</td>
                <td class="bold right" style="width: 20%;">P.U.</td>
                <td class="bold right" style="width: 20%;">Total</td>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
            <tr>
                <td class="item-name">
                    @if(isset($type) && $type === 'client')
                        {{ $item->product->name ?? 'Producto' }}
                        <br><span class="small">{{ str_pad($item->product_id, 12, '0', STR_PAD_LEFT) }}</span>
                    @else
                        {{ $item->product->name ?? 'Producto' }}
                        @if($item->variant)
                            <br><span class="small">{{ $item->variant->sku }}</span>
                        @endif
                    @endif
                </td>
                <td class="center">{{ $item->quantity }}</td>
                <td class="right">${{ number_format($item->unit_price, 2) }}</td>
                <td class="right">${{ number_format($item->total, 2) }}</td>
            </tr>
            @if($item->discount_amount > 0)
            <tr>
                <td colspan="3" class="small right">Desc:</td>
                <td class="right small">-${{ number_format($item->discount_amount, 2) }}</td>
            </tr>
            @endif
            @endforeach
        </tbody>
    </table>

    <div class="divider"></div>

    {{-- ===== TOTALS ===== --}}
    <table>
        <tr>
            <td>Subtotal:</td>
            <td class="right">${{ number_format($order->subtotal, 2) }}</td>
        </tr>
        @if($order->tax_amount > 0)
        <tr>
            <td>IVA:</td>
            <td class="right">${{ number_format($order->tax_amount, 2) }}</td>
        </tr>
        @endif
        @if($order->discount_amount > 0)
        <tr>
            <td>Descuento:</td>
            <td class="right">-${{ number_format($order->discount_amount, 2) }}</td>
        </tr>
        @endif
        <tr class="total-row">
            <td>TOTAL:</td>
            <td class="right">${{ number_format($order->total, 2) }}</td>
        </tr>
    </table>

    <div class="divider"></div>

    {{-- ===== PAYMENTS ===== --}}
    @if(isset($order->payments) && $order->payments->count() > 0)
    <table>
        @foreach($order->payments as $payment)
        <tr>
            <td>
                @switch($payment->method)
                    @case('cash') Efectivo @break
                    @case('card') Tarjeta @break
                    @case('transfer') Transferencia @break
                    @case('credit') Crédito @break
                    @default {{ $payment->method }}
                @endswitch
            </td>
            <td class="right">${{ number_format($payment->amount, 2) }}</td>
        </tr>
        @endforeach
    </table>

    @if($order->payments->where('method', 'cash')->count() > 0)
        @php
            $cashPaid = $order->payments->where('method', 'cash')->sum('amount');
            $changeAmount = max(0, $cashPaid - $order->total);
        @endphp
        @if($changeAmount > 0)
        <table>
            <tr class="bold">
                <td>Cambio:</td>
                <td class="right">${{ number_format($changeAmount, 2) }}</td>
            </tr>
        </table>
        @endif
    @endif

    <div class="divider"></div>
    @endif

    {{-- ===== FOOTER ===== --}}
    <div class="center small" style="margin-top: 4px;">
        <p>*** GRACIAS POR SU COMPRA ***</p>
        <p>Este ticket no es una factura</p>
        <p style="margin-top: 2px;">{{ now()->format('d/m/Y H:i:s') }}</p>
    </div>

    {{-- ===== BARCODE ===== --}}
    @if(isset($barcodeSvg))
    <div style="margin-top: 10px; text-align: center;">
        <img src="{{ $barcodeSvg }}" style="width: 55mm; height: auto;" />
        <div style="font-size: 10px; font-family: 'Courier New', monospace; letter-spacing: 3px; margin-top: 2px;">{{ $barcodeValue ?? '' }}</div>
    </div>
    @endif
</body>
</html>
