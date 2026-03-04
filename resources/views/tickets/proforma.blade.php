<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        /* Proforma – half-letter landscape */
        @page {
            margin: 6mm 8mm;
            size: letter landscape;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #000;
        }
        .center { text-align: center; }
        .right  { text-align: right; }
        .bold   { font-weight: bold; }
        .small  { font-size: 9px; color: #444; }

        /* Header */
        .header { text-align: center; margin-bottom: 6px; }
        .header h1 { font-size: 15px; margin: 0; letter-spacing: 1px; }
        .header .contact { font-size: 9px; color: #333; margin: 2px 0; }
        .header .doc-type { font-size: 16px; font-weight: bold; margin: 4px 0 0; letter-spacing: 2px; }
        .header .doc-date { font-size: 10px; color: #555; }

        /* Order info */
        .order-info { margin: 4px 0 6px; font-size: 10px; }
        .order-info span { margin-right: 20px; }

        /* Items table */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .items-table thead td {
            font-weight: bold;
            border-bottom: 2px solid #000;
            border-top: 1px solid #000;
            padding: 3px 4px;
            font-size: 10px;
            background: #f5f5f5;
        }
        .items-table tbody td {
            padding: 3px 4px;
            vertical-align: top;
            border-bottom: 1px solid #ddd;
        }
        .items-table .desc-col { width: 48%; }
        .items-table .code-col { width: 20%; }
        .items-table .qty-col  { width: 14%; text-align: center; }
        .items-table .price-col { width: 18%; text-align: right; }

        /* Sub-lines for alternative brands/prices */
        .sub-lines {
            font-size: 9px;
            color: #333;
            margin-top: 2px;
            padding-left: 8px;
        }
        .sub-lines div { margin: 1px 0; }

        /* Totals */
        .totals-table { width: 100%; border-collapse: collapse; }
        .totals-table td { padding: 1px 4px; font-size: 10px; }
        .totals-table .label { text-align: right; width: 80%; }
        .totals-table .value { text-align: right; width: 20%; }
        .totals-table .total-final td {
            font-weight: bold;
            font-size: 13px;
            border-top: 2px solid #000;
            padding-top: 3px;
        }

        /* Footer */
        .footer {
            margin-top: 10px;
            text-align: center;
            font-size: 10px;
        }
        .footer .thanks { font-style: italic; margin-bottom: 6px; }

        /* Barcode area */
        .barcode-area {
            text-align: center;
            margin-top: 8px;
        }
        .barcode-bars {
            font-family: 'Courier New', monospace;
            font-size: 32px;
            line-height: 1;
            letter-spacing: -1px;
        }
        .barcode-number {
            font-family: 'Courier New', monospace;
            font-size: 11px;
            letter-spacing: 3px;
            margin-top: 2px;
        }

        .article-count {
            font-size: 10px;
            margin-top: 6px;
        }
    </style>
</head>
<body>
    {{-- ===== HEADER ===== --}}
    <div class="header">
        <h1>{{ $businessName ?? 'Mi Negocio' }}</h1>
        @if(!empty($businessPhone) || !empty($businessEmail))
            <div class="contact">
                @if(!empty($businessPhone))
                    Whatsapp: {{ $businessPhone }}
                @endif
                @if(!empty($businessEmail))
                    Email: {{ $businessEmail }}
                @endif
            </div>
        @endif
        @if(!empty($businessAddress))
            <div class="contact">{{ $businessAddress }}</div>
        @endif
        <div class="doc-type">PROFORM</div>
        <div class="doc-date">{{ $order->created_at->format('d/m/Y h:i a') }}</div>
    </div>

    {{-- ===== ORDER INFO ===== --}}
    <div class="order-info">
        <span><strong>Ticket:</strong> {{ $order->order_number }}</span>
        <span><strong>Vendedor:</strong> {{ $order->user->name ?? 'N/A' }}</span>
        @if($order->customer)
            <span><strong>Cliente:</strong> {{ $order->customer->name }}</span>
        @endif
    </div>

    {{-- ===== ITEMS TABLE ===== --}}
    <table class="items-table">
        <thead>
            <tr>
                <td class="desc-col">Description</td>
                <td class="code-col">Code</td>
                <td class="qty-col">Qty</td>
                <td class="price-col">Price</td>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
            @php
                $product = $item->product;
                $variant = $item->variant;

                // ── Description ──
                if ($type === 'client') {
                    // Client: show only category/short name
                    $categoryName = $product->category->name ?? '';
                    $productName = $product->name ?? 'Producto';
                    $displayDesc = $categoryName ? ($categoryName . '/' . $productName) : $productName;
                } else {
                    // Internal: full product name (already auto-generated with Cat+Sub+Brand+Attrs)
                    $displayDesc = $product->name ?? 'Producto';

                    // Append VIN compatibility if available
                    if ($product->relationLoaded('vins') && $product->vins->count() > 0) {
                        $vinList = $product->vins->pluck('vin')->implode(' ');
                        $displayDesc .= ' ' . $vinList;
                    }
                }

                // ── Code ──
                if ($type === 'client') {
                    $displayCode = str_pad($item->product_id, 12, '0', STR_PAD_LEFT);
                } else {
                    $displayCode = $variant ? $variant->sku : ($product->sku_base ?? '#' . $item->product_id);
                }

                // ── Sub-lines: sibling variants with brand name and price ──
                $siblingLines = [];
                if ($product->relationLoaded('variants')) {
                    foreach ($product->variants as $sib) {
                        $brandName = ($sib->relationLoaded('brand') && $sib->brand) ? $sib->brand->name : '';
                        $sibPrice = ($sib->relationLoaded('pricing') && $sib->pricing) ? $sib->pricing->price : null;
                        if ($brandName && $sibPrice !== null) {
                            $siblingLines[] = '$' . number_format($sibPrice, 2) . ' ' . strtoupper($brandName);
                        }
                    }
                }
            @endphp
            <tr>
                <td class="desc-col">
                    <strong>{{ $displayDesc }}</strong>
                    @if(count($siblingLines) > 0)
                        <div class="sub-lines">
                            @foreach($siblingLines as $line)
                                <div>{{ $line }}</div>
                            @endforeach
                        </div>
                    @endif
                </td>
                <td class="code-col">{{ $displayCode }}</td>
                <td class="qty-col">{{ $item->quantity }} x<br>${{ number_format($item->unit_price, 2) }}</td>
                <td class="price-col">${{ number_format($item->total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ===== TOTALS ===== --}}
    <table class="totals-table">
        <tr>
            <td class="label">Subtotal</td>
            <td class="value">${{ number_format($order->subtotal, 2) }}</td>
        </tr>
        @if($order->tax_amount > 0)
        <tr>
            <td class="label">Tax (${{ number_format($order->subtotal, 2) }} @ {{ $taxPercent ?? '7' }}%)</td>
            <td class="value">${{ number_format($order->tax_amount, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Total impuestos</td>
            <td class="value">${{ number_format($order->tax_amount, 2) }}</td>
        </tr>
        @endif
        @if($order->discount_amount > 0)
        <tr>
            <td class="label">Descuento</td>
            <td class="value">-${{ number_format($order->discount_amount, 2) }}</td>
        </tr>
        @endif
        <tr class="total-final">
            <td class="label">Total</td>
            <td class="value">${{ number_format($order->total, 2) }}</td>
        </tr>
    </table>

    {{-- ===== FOOTER ===== --}}
    <div class="footer">
        <div class="article-count">
            Total artículos: {{ $order->items->sum('quantity') }}
        </div>

        <div class="thanks">Gracias por su compra !</div>

        {{-- Barcode --}}
        @if(isset($barcodeSvg))
        <div class="barcode-area">
            <img src="{{ $barcodeSvg }}" style="width: 50mm; height: auto;" />
            <div class="barcode-number">{{ $barcodeValue ?? '' }}</div>
        </div>
        @endif
    </div>
</body>
</html>
