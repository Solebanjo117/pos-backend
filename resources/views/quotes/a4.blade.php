<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cotización {{ $quote->quote_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 12px; color: #1a1a1a; padding: 30px; }
        .header { display: flex; justify-content: space-between; border-bottom: 3px solid #2563eb; padding-bottom: 16px; margin-bottom: 24px; }
        .header .company { max-width: 60%; }
        .header .company h1 { font-size: 22px; color: #2563eb; margin-bottom: 4px; }
        .header .company p { font-size: 10px; color: #64748b; line-height: 1.5; }
        .header .quote-meta { text-align: right; }
        .header .quote-meta h2 { font-size: 18px; color: #1e293b; margin-bottom: 6px; }
        .header .quote-meta .label { font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
        .header .quote-meta .value { font-size: 13px; font-weight: bold; color: #1e293b; margin-bottom: 6px; }

        .customer-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px; margin-bottom: 24px; }
        .customer-box h3 { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; margin-bottom: 6px; }
        .customer-box .name { font-size: 14px; font-weight: bold; color: #1e293b; }
        .customer-box .detail { font-size: 11px; color: #64748b; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        thead th { background: #2563eb; color: #fff; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; padding: 10px 8px; text-align: left; }
        tbody td { padding: 10px 8px; border-bottom: 1px solid #e2e8f0; font-size: 11px; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .totals { width: 280px; margin-left: auto; }
        .totals .row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 12px; }
        .totals .row.total { border-top: 2px solid #2563eb; font-size: 16px; font-weight: bold; color: #2563eb; padding-top: 10px; margin-top: 4px; }

        .footer { margin-top: 40px; border-top: 1px solid #e2e8f0; padding-top: 16px; }
        .footer .validity { background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 10px 14px; text-align: center; font-size: 11px; color: #92400e; font-weight: bold; margin-bottom: 16px; }
        .footer .notes { font-size: 10px; color: #64748b; line-height: 1.5; }
        .footer .fine-print { margin-top: 20px; text-align: center; font-size: 9px; color: #cbd5e1; }
    </style>
</head>
<body>
    {{-- HEADER --}}
    <div class="header">
        <div class="company">
            <h1>{{ $businessName }}</h1>
            @if($businessRfc)
                <p>RFC: {{ $businessRfc }}</p>
            @endif
            @if($businessAddr)
                <p>{{ $businessAddr }}</p>
            @endif
            @if($businessPhone)
                <p>Tel: {{ $businessPhone }}</p>
            @endif
        </div>
        <div class="quote-meta">
            <h2>COTIZACIÓN</h2>
            <p class="label">No. Cotización</p>
            <p class="value">{{ $quote->quote_number }}</p>
            <p class="label">Fecha</p>
            <p class="value">{{ $quote->date->format('d/m/Y') }}</p>
            @if($quote->valid_until)
                <p class="label">Válida hasta</p>
                <p class="value">{{ $quote->valid_until->format('d/m/Y') }}</p>
            @endif
        </div>
    </div>

    {{-- CUSTOMER --}}
    <div class="customer-box">
        <h3>Cliente</h3>
        @if($quote->customer)
            <p class="name">{{ $quote->customer->name }}</p>
            <p class="detail">{{ $quote->customer->email }} • {{ $quote->customer->phone }}</p>
            @if($quote->customer->address)
                <p class="detail">{{ $quote->customer->address }}</p>
            @endif
        @else
            <p class="name">PÚBLICO GENERAL</p>
        @endif
    </div>

    {{-- ITEMS TABLE --}}
    <table>
        <thead>
            <tr>
                <th style="width:5%;">#</th>
                <th style="width:40%;">Descripción</th>
                <th class="text-center" style="width:10%;">Cant.</th>
                <th class="text-right" style="width:15%;">P. Unitario</th>
                <th class="text-right" style="width:10%;">Desc.</th>
                <th class="text-right" style="width:20%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quote->items as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>
                        <strong>{{ $item->product->name ?? 'Producto' }}</strong>
                        @if($item->variant)
                            <br>
                            <span style="color:#64748b;font-size:10px;">
                                @if(isset($mode) && $mode === 'blind')
                                    {{ $item->variant->name ?? '' }} (ID: {{ $item->variant->id }})
                                @else
                                    {{ $item->variant->sku ?? $item->variant->name ?? '' }}
                                @endif
                            </span>
                        @endif
                    </td>
                    <td class="text-center">{{ $item->quantity }}</td>
                    <td class="text-right">${{ number_format($item->unit_price, 2) }}</td>
                    <td class="text-right">
                        @if($item->discount_percent > 0)
                            {{ $item->discount_percent }}%
                        @else
                            -
                        @endif
                    </td>
                    <td class="text-right"><strong>${{ number_format($item->total, 2) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- TOTALS --}}
    <div class="totals">
        <div class="row">
            <span>Subtotal</span>
            <span>${{ number_format($quote->subtotal, 2) }}</span>
        </div>
        @if($quote->discount_amount > 0)
            <div class="row">
                <span>Descuento</span>
                <span>-${{ number_format($quote->discount_amount, 2) }}</span>
            </div>
        @endif
        @if($quote->tax_amount > 0)
            <div class="row">
                <span>Impuesto</span>
                <span>${{ number_format($quote->tax_amount, 2) }}</span>
            </div>
        @endif
        <div class="row total">
            <span>TOTAL</span>
            <span>${{ number_format($quote->total, 2) }}</span>
        </div>
    </div>

    {{-- FOOTER --}}
    <div class="footer">
        @if($quote->valid_until)
            <div class="validity">
                Esta cotización es válida hasta el {{ $quote->valid_until->format('d/m/Y') }}
            </div>
        @endif

        @if($quote->notes)
            <div class="notes">
                <strong>Notas:</strong> {{ $quote->notes }}
            </div>
        @endif

        <p class="fine-print">
            Los precios están sujetos a disponibilidad de inventario al momento de la compra. • {{ $businessName }}
        </p>
    </div>
</body>
</html>
