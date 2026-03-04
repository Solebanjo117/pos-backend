<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Histórico de Movimientos</title>
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
            background-color: #F3F4F6;
            border: 1px solid #D1D5DB;
            flex: 1;
            margin: 0 5px;
        }
        .summary-card h3 {
            margin: 0;
            font-size: 20px;
            color: #4F46E5;
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
            font-size: 9px;
        }
        td {
            padding: 6px 8px;
            border-bottom: 1px solid #E5E7EB;
            font-size: 9px;
        }
        tr:nth-child(even) {
            background-color: #F9FAFB;
        }
        .type {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 8px;
            font-weight: bold;
        }
        .type.in { background-color: #D1FAE5; color: #065F46; }
        .type.out { background-color: #FEE2E2; color: #991B1B; }
        .type.transfer { background-color: #DBEAFE; color: #1E40AF; }
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
        <h1>Histórico de Movimientos de Inventario</h1>
        <p>Generado el: {{ $generatedAt }}</p>
    </div>

    <div class="summary">
        <div class="summary-card">
            <h3>{{ $report['summary']['total_movements'] }}</h3>
            <p>Total Movimientos</p>
        </div>
        <div class="summary-card">
            <h3>{{ $report['summary']['total_inbound'] }}</h3>
            <p>Entradas</p>
        </div>
        <div class="summary-card">
            <h3>{{ $report['summary']['total_outbound'] }}</h3>
            <p>Salidas</p>
        </div>
        <div class="summary-card">
            <h3>{{ $report['summary']['total_transfers'] }}</h3>
            <p>Transferencias</p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>SKU</th>
                <th>Producto</th>
                <th>Almacén</th>
                <th>Destino</th>
                <th>Tipo</th>
                <th>Cantidad</th>
                <th>Usuario</th>
            </tr>
        </thead>
        <tbody>
            @foreach($report['movements'] as $movement)
            <tr>
                <td>{{ \Carbon\Carbon::parse($movement['date'])->format('d/m/Y H:i') }}</td>
                <td>{{ $movement['product_sku'] }}</td>
                <td>{{ $movement['product_name'] }}</td>
                <td>{{ $movement['warehouse'] }}</td>
                <td>{{ $movement['destination_warehouse'] ?? '-' }}</td>
                <td>
                    <span class="type {{ $movement['movement_type'] }}">
                        @if($movement['movement_type'] === 'in')
                            Entrada
                        @elseif($movement['movement_type'] === 'out')
                            Salida
                        @else
                            Transfer
                        @endif
                    </span>
                </td>
                <td>{{ $movement['quantity'] }}</td>
                <td>{{ $movement['user'] ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p>Sistema POS - Reporte de Inventario</p>
    </div>
</body>
</html>
