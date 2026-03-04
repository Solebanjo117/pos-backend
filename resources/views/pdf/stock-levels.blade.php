<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reporte de Niveles de Stock</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 10px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #4F46E5;
            padding-bottom: 10px;
        }
        .header h1 {
            color: #4F46E5;
            margin: 0;
            font-size: 24px;
        }
        .header p {
            margin: 5px 0;
            color: #666;
        }
        .summary {
            display: flex;
            justify-content: space-around;
            margin-bottom: 20px;
        }
        .summary-card {
            text-align: center;
            padding: 10px;
            border-radius: 5px;
            flex: 1;
            margin: 0 5px;
        }
        .summary-card.critical { background-color: #FEF2F2; border: 1px solid #EF4444; }
        .summary-card.low { background-color: #FEF9C3; border: 1px solid #EAB308; }
        .summary-card.normal { background-color: #EFF6FF; border: 1px solid: #3B82F6; }
        .summary-card.optimal { background-color: #F0FDF4; border: 1px solid #22C55E; }
        .summary-card h3 {
            margin: 0;
            font-size: 24px;
        }
        .summary-card p {
            margin: 5px 0 0 0;
            font-size: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th {
            background-color: #4F46E5;
            color: white;
            padding: 8px;
            text-align: left;
            font-weight: bold;
        }
        td {
            padding: 6px 8px;
            border-bottom: 1px solid #E5E7EB;
        }
        tr:nth-child(even) {
            background-color: #F9FAFB;
        }
        .status {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: bold;
        }
        .status.critical { background-color: #FEE2E2; color: #991B1B; }
        .status.low { background-color: #FEF3C7; color: #92400E; }
        .status.normal { background-color: #DBEAFE; color: #1E40AF; }
        .status.optimal { background-color: #D1FAE5; color: #065F46; }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 9px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Reporte de Niveles de Stock</h1>
        <p>Generado el: {{ $generatedAt }}</p>
    </div>

    <div class="summary">
        <div class="summary-card critical">
            <h3>{{ $report['summary']['critical_stock'] }}</h3>
            <p>Crítico</p>
        </div>
        <div class="summary-card low">
            <h3>{{ $report['summary']['low_stock'] }}</h3>
            <p>Bajo</p>
        </div>
        <div class="summary-card normal">
            <h3>{{ $report['summary']['normal_stock'] }}</h3>
            <p>Normal</p>
        </div>
        <div class="summary-card optimal">
            <h3>{{ $report['summary']['optimal_stock'] }}</h3>
            <p>Óptimo</p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>SKU</th>
                <th>Producto</th>
                <th>Marca</th>
                <th>Almacén</th>
                <th>Stock</th>
                <th>Reorden</th>
                <th>Est ado</th>
                <th>Valor</th>
            </tr>
        </thead>
        <tbody>
            @foreach($report['products'] as $product)
            <tr>
                <td>{{ $product['sku'] }}</td>
                <td>{{ $product['name'] }}</td>
                <td>{{ $product['brand'] ?? '-' }}</td>
                <td>{{ $product['warehouse'] }}</td>
                <td>{{ $product['current_stock'] }}</td>
                <td>{{ $product['reorder_point'] }}</td>
                <td>
                    <span class="status {{ $product['status'] }}">
                        {{ ucfirst($product['status']) }}
                    </span>
                </td>
                <td>${{ number_format($product['stock_value'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p>Sistema POS - Reporte de Inventario</p>
    </div>
</body>
</html>
