<?php

namespace App\Services;

use App\Models\ProductInventory;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Get dashboard statistics.
     */
    public function getStats(?int $warehouseId = null): array
    {
        return [
            'today_sales' => $this->getTodaySales($warehouseId),
            'today_profit' => $this->getTodayProfit($warehouseId),
            'pending_orders' => $this->getPendingOrders($warehouseId),
            'low_stock_items' => $this->getLowStockItemsCount($warehouseId),
            'active_alerts' => $this->getActiveAlertsCount($warehouseId),
            'total_inventory_value' => $this->getTotalInventoryValue($warehouseId),
        ];
    }

    public function getFinancialDashboard(
        ?int $warehouseId = null,
        string $period = '7d',
        ?string $dateFrom = null,
        ?string $dateTo = null,
        bool $compare = false,
    ): array {
        $periodData = $this->resolveFinancialPeriod($period, $dateFrom, $dateTo);
        $metrics = $this->financialMetrics(
            $periodData['date_from'],
            $periodData['date_to'],
            $warehouseId
        );
        $previousMetrics = $compare
            ? $this->financialMetrics($periodData['previous_date_from'], $periodData['previous_date_to'], $warehouseId)
            : null;

        return [
            'period' => [
                'key' => $periodData['key'],
                'date_from' => $periodData['date_from']->toDateString(),
                'date_to' => $periodData['date_to']->toDateString(),
                'previous_date_from' => $periodData['previous_date_from']->toDateString(),
                'previous_date_to' => $periodData['previous_date_to']->toDateString(),
            ],
            'kpis' => $this->financialKpis($metrics, $previousMetrics),
            'payment_methods' => $this->financialPaymentMethods($periodData['date_from'], $periodData['date_to'], $warehouseId),
            'top_clients' => $this->financialTopClients($periodData['date_from'], $periodData['date_to'], $warehouseId),
            'latest_transactions' => $this->financialLatestTransactions($periodData['date_from'], $periodData['date_to'], $warehouseId),
            'alerts' => $this->financialAlerts($metrics, $warehouseId),
            'report_links' => [
                'income_statement' => '/reports/financial',
                'financial_position' => '/reports/financial',
                'sales_by_client' => '/reports/sales-by-client',
                'accounts_receivable' => '/ar',
                'z_report' => '/reports/z-report',
            ],
        ];
    }

    /**
     * Get today's sales total.
     */
    private function getTodaySales(?int $warehouseId = null): float
    {
        $query = SalesOrder::whereDate('created_at', Carbon::today())
            ->whereIn('status', ['confirmed', 'processing', 'completed']);

        // Filter by warehouse if specified
        if ($warehouseId) {
            $query->whereHas('items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        return (float) $query->sum('total');
    }

    /**
     * Get today's gross profit.
     */
    private function getTodayProfit(?int $warehouseId = null): float
    {
        $query = SalesOrderItem::whereHas('salesOrder', function ($q) {
            $q->whereDate('created_at', Carbon::today())
                ->whereIn('status', ['confirmed', 'processing', 'completed']);
        });

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $items = $query->get();

        return $items->sum(function ($item) {
            $effectivePrice = (float) $item->unit_price * (1 - ((float) $item->discount_percent / 100));

            return ($effectivePrice - (float) $item->unit_cost) * $item->quantity;
        });
    }

    /**
     * Get pending orders count.
     */
    private function getPendingOrders(?int $warehouseId = null): int
    {
        $query = SalesOrder::whereIn('status', ['draft', 'pending']);

        // Filter by warehouse if specified
        if ($warehouseId) {
            $query->whereHas('items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        return $query->count();
    }

    /**
     * Get count of products with low stock.
     */
    private function getLowStockItemsCount(?int $warehouseId = null): int
    {
        $query = ProductInventory::whereColumn('quantity_available', '<=', 'reorder_point');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query->count();
    }

    /**
     * Get count of active alerts.
     */
    private function getActiveAlertsCount(?int $warehouseId = null): int
    {
        // For now, active alerts = low stock items
        return $this->getLowStockItemsCount($warehouseId);
    }

    /**
     * Calculate the total COG (Cost of Goods) value of current inventory.
     */
    private function getTotalInventoryValue(?int $warehouseId = null): float
    {
        return app(InventoryFifoService::class)->availableStockValue($warehouseId);
    }

    /**
     * Get top selling products.
     */
    public function getTopProducts(int $limit = 5, ?int $warehouseId = null, ?string $period = '30d'): array
    {
        // Calculate date range based on period
        $startDate = $this->getPeriodStartDate($period);

        $query = SalesOrderItem::select('product_id', 'variant_id')
            ->selectRaw('SUM(quantity) as total_quantity')
            ->selectRaw('SUM(total) as total_sales')
            ->whereHas('salesOrder', function ($q) use ($startDate) {
                $q->where('created_at', '>=', $startDate)
                    ->whereIn('status', ['confirmed', 'processing', 'completed']);
            });

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $topItems = $query->groupBy('product_id', 'variant_id')
            ->orderByDesc('total_quantity')
            ->limit($limit)
            ->with(['product', 'variant'])
            ->get();

        return $topItems->map(function ($item) {
            $name = $item->product->name;
            if ($item->variant) {
                $name .= ' - '.$item->variant->name;
            }

            return [
                'name' => $name,
                'sales' => (int) $item->total_quantity,
                'revenue' => (float) $item->total_sales,
            ];
        })->toArray();
    }

    /**
     * Get sales chart data for a period.
     */
    public function getSalesChartData(string $period = '7d', ?int $warehouseId = null): array
    {
        $startDate = $this->getPeriodStartDate($period);
        $endDate = Carbon::now();

        // Use DB::table to avoid Eloquent model casting interference with DATE()
        $salesQuery = DB::table('sales_orders')
            ->select(DB::raw('DATE(created_at) as sale_date'), DB::raw('COALESCE(SUM(total), 0) as total'))
            ->where('created_at', '>=', $startDate->toDateTimeString())
            ->where('created_at', '<=', $endDate->toDateTimeString())
            ->whereIn('status', ['confirmed', 'processing', 'completed']);

        if ($warehouseId) {
            $salesQuery->whereExists(function ($q) use ($warehouseId) {
                $q->select(DB::raw(1))
                    ->from('sales_order_items')
                    ->whereColumn('sales_order_items.sales_order_id', 'sales_orders.id')
                    ->where('sales_order_items.warehouse_id', $warehouseId);
            });
        }

        $salesData = $salesQuery->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('sale_date')
            ->get();

        // Profit query using DB::table
        $profitQuery = DB::table('sales_order_items')
            ->select(
                DB::raw('DATE(sales_orders.created_at) as sale_date'),
                DB::raw('COALESCE(SUM(sales_order_items.unit_cost * sales_order_items.quantity), 0) as total_cost'),
                DB::raw('COALESCE(SUM((sales_order_items.unit_price * (1 - sales_order_items.discount_percent / 100) - sales_order_items.unit_cost) * sales_order_items.quantity), 0) as profit')
            )
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_orders.created_at', '>=', $startDate->toDateTimeString())
            ->where('sales_orders.created_at', '<=', $endDate->toDateTimeString())
            ->whereIn('sales_orders.status', ['confirmed', 'processing', 'completed']);

        if ($warehouseId) {
            $profitQuery->where('sales_order_items.warehouse_id', $warehouseId);
        }

        $profitDataRaw = $profitQuery->groupBy(DB::raw('DATE(sales_orders.created_at)'))
            ->orderBy('sale_date')
            ->get();

        // Build arrays for all dates in range
        $labels = [];
        $revenueData = [];
        $profitData = [];
        $costData = [];
        $currentDate = Carbon::parse($startDate);

        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->format('Y-m-d');
            $labels[] = $currentDate->format('M d');

            $dayData = $salesData->firstWhere('sale_date', $dateStr);
            $revenueData[] = $dayData ? (float) $dayData->total : 0;

            $dayProfit = $profitDataRaw->firstWhere('sale_date', $dateStr);
            $profitData[] = $dayProfit ? round((float) $dayProfit->profit, 2) : 0;
            $costData[] = $dayProfit ? round((float) $dayProfit->total_cost, 2) : 0;

            $currentDate->addDay();
        }

        return [
            'labels' => $labels,
            'data' => $revenueData,
            'profit' => $profitData,
            'cost' => $costData,
        ];
    }

    /**
     * Get products with low stock.
     */
    public function getLowStockProducts(int $limit = 10, ?int $warehouseId = null): array
    {
        $query = ProductInventory::with(['product', 'variant', 'warehouse'])
            ->whereColumn('quantity_available', '<=', 'reorder_point')
            ->orderBy('quantity_available', 'asc')
            ->limit($limit);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $lowStockInventory = $query->get();

        return $lowStockInventory->map(function ($inventory) {
            $name = $inventory->product ? $inventory->product->name : 'Producto Desconocido';
            if ($inventory->variant) {
                $name .= ' - '.$inventory->variant->name;
            }

            return [
                'product_id' => $inventory->product_id,
                'name' => $name,
                'sku' => $inventory->variant ? $inventory->variant->sku : ($inventory->product ? $inventory->product->sku_base : 'N/A'),
                'stock' => $inventory->quantity_available,
                'reorder_point' => $inventory->reorder_point,
                'warehouse' => $inventory->warehouse ? $inventory->warehouse->name : 'General',
            ];
        })->toArray();
    }

    /**
     * Get inventory summary by warehouse.
     */
    public function getInventorySummary(?int $warehouseId = null): array
    {
        $query = ProductInventory::select('warehouse_id')
            ->selectRaw('SUM(quantity_available) as total_available')
            ->selectRaw('SUM(quantity_reserved) as total_reserved')
            ->selectRaw('0 as total_on_order')
            ->selectRaw('COUNT(*) as product_count')
            ->with('warehouse:id,name')
            ->groupBy('warehouse_id');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $summary = $query->get();

        return $summary->map(function ($item) {
            return [
                'warehouse_id' => $item->warehouse_id,
                'warehouse_name' => $item->warehouse->name,
                'total_available' => (int) $item->total_available,
                'total_reserved' => (int) $item->total_reserved,
                'total_on_order' => 0,
                'product_count' => (int) $item->product_count,
            ];
        })->toArray();
    }

    /**
     * Get products that need reordering with details.
     */
    public function getReorderAlerts(?int $warehouseId = null): array
    {
        $query = ProductInventory::with(['product', 'variant.brand', 'warehouse'])
            ->whereColumn('quantity_available', '<=', 'reorder_point');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $needsReorder = $query->get();

        return $needsReorder->map(function ($inventory) {
            $name = $inventory->product ? $inventory->product->name : 'Producto Desconocido';
            if ($inventory->variant) {
                $name .= ' - '.$inventory->variant->name;
            }

            $targetLevel = $inventory->reorder_point + $inventory->reorder_quantity;

            return [
                'product_id' => $inventory->product_id,
                'product_name' => $name,
                'sku' => $inventory->variant ? $inventory->variant->sku : ($inventory->product ? $inventory->product->sku_base : 'N/A'),
                'brand' => $inventory->variant && $inventory->variant->brand ? $inventory->variant->brand->name : 'N/A',
                'warehouse' => $inventory->warehouse ? $inventory->warehouse->name : 'General',
                'current_stock' => $inventory->quantity_available,
                'reorder_point' => $inventory->reorder_point,
                'target_level' => $targetLevel,
                'shortage' => max(0, $targetLevel - $inventory->quantity_available),
            ];
        })->toArray();
    }

    /**
     * Helper method to get start date based on period string.
     */
    private function getPeriodStartDate(string $period): Carbon
    {
        return match ($period) {
            'today' => Carbon::now()->startOfDay(),
            'week' => Carbon::now()->startOfWeek(),
            'month' => Carbon::now()->startOfMonth(),
            'quarter' => Carbon::now()->startOfQuarter(),
            'year', 'ytd' => Carbon::now()->startOfYear(),
            '7d' => Carbon::now()->subDays(7),
            '30d' => Carbon::now()->subDays(30),
            '90d' => Carbon::now()->subDays(90),
            default => Carbon::now()->subDays(7),
        };
    }

    private function resolveFinancialPeriod(string $period, ?string $dateFrom, ?string $dateTo): array
    {
        $normalizedPeriod = match ($period) {
            'today', 'week', 'month', 'quarter', 'year', 'custom', '7d', '30d', '90d', 'ytd' => $period,
            default => '7d',
        };

        if ($normalizedPeriod === 'custom' && $dateFrom && $dateTo) {
            $from = Carbon::parse($dateFrom)->startOfDay();
            $to = Carbon::parse($dateTo)->endOfDay();
        } else {
            $from = $this->getPeriodStartDate($normalizedPeriod)->startOfDay();
            $to = Carbon::now()->endOfDay();
        }

        if ($normalizedPeriod === 'custom' && $from->isStartOfDay() && $from->isSameDay($from->copy()->startOfMonth()) && $to->isSameDay($to->copy()->endOfMonth())) {
            $previousFrom = $from->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay();
            $previousTo = $from->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay();
        } else {
            $days = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
            $previousTo = $from->copy()->subDay()->endOfDay();
            $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();
        }

        return [
            'key' => $normalizedPeriod,
            'date_from' => $from,
            'date_to' => $to,
            'previous_date_from' => $previousFrom,
            'previous_date_to' => $previousTo,
        ];
    }

    private function financialMetrics(Carbon $dateFrom, Carbon $dateTo, ?int $warehouseId): array
    {
        $orders = $this->financialOrdersQuery($dateFrom, $dateTo, $warehouseId)
            ->with(['payments', 'items'])
            ->get();

        $totalRevenue = round((float) $orders->sum('total'), 2);
        $cashCollected = round((float) $orders
            ->flatMap(fn (SalesOrder $order) => $order->payments)
            ->where('method', '!=', 'credit')
            ->sum('amount'), 2);
        $accountsReceivable = round((float) $orders->sum(fn (SalesOrder $order): float => $order->amount_due), 2);
        $cogs = round((float) $orders
            ->flatMap(fn (SalesOrder $order) => $order->items)
            ->when($warehouseId, fn ($items) => $items->where('warehouse_id', $warehouseId))
            ->sum(fn (SalesOrderItem $item): float => (float) $item->unit_cost * (int) $item->quantity), 2);
        $inventoryValue = round($this->getTotalInventoryValue($warehouseId), 2);
        $pendingOrders = $this->getPendingOrders($warehouseId);
        $lowStockItems = $this->getLowStockItemsCount($warehouseId);

        return [
            'total_revenue' => $totalRevenue,
            'accounts_receivable' => $accountsReceivable,
            'net_profit' => round($totalRevenue - $cogs, 2),
            'cogs_expenses' => $cogs,
            'cash_collected' => $cashCollected,
            'inventory_value' => $inventoryValue,
            'pending_orders' => $pendingOrders,
            'low_stock_items' => $lowStockItems,
        ];
    }

    private function financialKpis(array $metrics, ?array $previousMetrics): array
    {
        return collect([
            ['key' => 'total_revenue', 'label' => 'Ingresos Totales', 'route' => '/reports/financial', 'tone' => 'blue', 'format' => 'currency'],
            ['key' => 'accounts_receivable', 'label' => 'Cuentas por Cobrar', 'route' => '/ar', 'tone' => 'orange', 'format' => 'currency'],
            ['key' => 'net_profit', 'label' => 'Utilidad Neta', 'route' => '/reports/financial', 'tone' => 'emerald', 'format' => 'currency'],
            ['key' => 'cogs_expenses', 'label' => 'Gastos / COGS', 'route' => '/reports/financial', 'tone' => 'red', 'format' => 'currency'],
            ['key' => 'cash_collected', 'label' => 'Caja Cobrada', 'route' => '/reports/z-report', 'tone' => 'green', 'format' => 'currency'],
            ['key' => 'inventory_value', 'label' => 'Valor Inventario', 'route' => '/inventory', 'tone' => 'indigo', 'format' => 'currency'],
            ['key' => 'pending_orders', 'label' => 'Ordenes Pendientes', 'route' => '/sales-orders', 'tone' => 'sky', 'format' => 'integer'],
            ['key' => 'low_stock_items', 'label' => 'Stock Bajo', 'route' => '/inventory', 'tone' => 'amber', 'format' => 'integer'],
        ])->map(function (array $definition) use ($metrics, $previousMetrics): array {
            $value = $metrics[$definition['key']] ?? 0;
            $previousValue = $previousMetrics[$definition['key']] ?? null;

            return [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'value' => $value,
                'formatted_value' => $definition['format'] === 'currency' ? $this->formatMoney($value) : number_format((float) $value, 0),
                'route' => $definition['route'],
                'tone' => $definition['tone'],
                'comparison' => $previousMetrics ? [
                    'previous_value' => $previousValue,
                    'formatted_previous_value' => $definition['format'] === 'currency' ? $this->formatMoney($previousValue) : number_format((float) $previousValue, 0),
                    'delta_percentage' => $this->deltaPercentage((float) $value, (float) $previousValue),
                ] : null,
            ];
        })->values()->all();
    }

    private function financialPaymentMethods(Carbon $dateFrom, Carbon $dateTo, ?int $warehouseId): array
    {
        $payments = $this->financialPaymentsQuery($dateFrom, $dateTo, $warehouseId)->get();
        $total = (float) $payments->sum('amount');

        return $payments
            ->groupBy('method')
            ->map(fn ($methodPayments, string $method): array => [
                'method' => $method,
                'label' => $this->paymentMethodLabel($method),
                'amount' => round((float) $methodPayments->sum('amount'), 2),
                'percentage' => $total > 0 ? round(((float) $methodPayments->sum('amount') / $total) * 100, 2) : 0.0,
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    private function financialTopClients(Carbon $dateFrom, Carbon $dateTo, ?int $warehouseId): array
    {
        return $this->financialOrdersQuery($dateFrom, $dateTo, $warehouseId)
            ->with(['customer:id,code,name', 'payments'])
            ->get()
            ->filter(fn (SalesOrder $order): bool => $order->customer !== null)
            ->groupBy('customer_id')
            ->map(function ($orders): array {
                $firstOrder = $orders->first();

                return [
                    'customer_id' => $firstOrder->customer_id,
                    'name' => $firstOrder->customer?->name ?? 'Cliente',
                    'total_revenue' => round((float) $orders->sum('total'), 2),
                    'pending_amount' => round((float) $orders->sum(fn (SalesOrder $order): float => $order->amount_due), 2),
                    'orders_count' => $orders->count(),
                    'route' => '/customers/'.$firstOrder->customer_id,
                ];
            })
            ->sortByDesc('total_revenue')
            ->take(5)
            ->values()
            ->all();
    }

    private function financialLatestTransactions(Carbon $dateFrom, Carbon $dateTo, ?int $warehouseId): array
    {
        return $this->financialPaymentsQuery($dateFrom, $dateTo, $warehouseId)
            ->with(['salesOrder.customer:id,name'])
            ->latest('paid_at')
            ->limit(8)
            ->get()
            ->map(fn (SalesOrderPayment $payment): array => [
                'id' => $payment->id,
                'type' => 'payment',
                'label' => $this->paymentMethodLabel($payment->method),
                'customer_id' => $payment->salesOrder?->customer_id,
                'customer_name' => $payment->salesOrder?->customer?->name,
                'reference' => $payment->salesOrder?->order_number,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'occurred_at' => $payment->paid_at?->toISOString(),
                'route' => $payment->sales_order_id ? '/sales-orders/'.$payment->sales_order_id : null,
            ])
            ->all();
    }

    private function financialAlerts(array $metrics, ?int $warehouseId): array
    {
        $alerts = [];

        if ((int) $metrics['low_stock_items'] > 0) {
            $alerts[] = [
                'type' => 'low_stock',
                'severity' => 'warning',
                'label' => 'Productos con stock bajo',
                'value' => (int) $metrics['low_stock_items'],
                'route' => '/inventory',
            ];
        }

        if ((float) $metrics['accounts_receivable'] > 0) {
            $alerts[] = [
                'type' => 'accounts_receivable',
                'severity' => 'attention',
                'label' => 'Credito pendiente por cobrar',
                'value' => (float) $metrics['accounts_receivable'],
                'route' => '/ar',
            ];
        }

        if ((int) $metrics['pending_orders'] > 0) {
            $alerts[] = [
                'type' => 'pending_orders',
                'severity' => 'info',
                'label' => 'Ordenes de venta pendientes',
                'value' => (int) $metrics['pending_orders'],
                'route' => '/sales-orders',
            ];
        }

        return $alerts;
    }

    private function financialOrdersQuery(Carbon $dateFrom, Carbon $dateTo, ?int $warehouseId)
    {
        return SalesOrder::query()
            ->whereBetween('date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->whereNotIn('status', ['cancelled'])
            ->when($warehouseId, fn ($query) => $query->whereHas('items', fn ($items) => $items->where('warehouse_id', $warehouseId)));
    }

    private function financialPaymentsQuery(Carbon $dateFrom, Carbon $dateTo, ?int $warehouseId)
    {
        return SalesOrderPayment::query()
            ->where('method', '!=', 'credit')
            ->whereBetween('paid_at', [$dateFrom, $dateTo])
            ->whereHas('salesOrder', fn ($query) => $query
                ->whereNotIn('status', ['cancelled'])
                ->when($warehouseId, fn ($orderQuery) => $orderQuery->whereHas('items', fn ($items) => $items->where('warehouse_id', $warehouseId))));
    }

    private function deltaPercentage(float $currentValue, float $previousValue): ?float
    {
        if ($previousValue == 0.0) {
            return $currentValue == 0.0 ? 0.0 : null;
        }

        return round((($currentValue - $previousValue) / abs($previousValue)) * 100, 2);
    }

    private function formatMoney(float|int|null $value): string
    {
        return '$'.number_format((float) $value, 2);
    }

    private function paymentMethodLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            'yappy' => 'Yappy',
            'credit' => 'Credito',
            default => str($method)->replace('_', ' ')->title()->toString(),
        };
    }

    /**
     * Get profitability by brand.
     */
    public function getProfitByBrand(?int $warehouseId = null, ?string $period = '30d'): array
    {
        $startDate = $this->getPeriodStartDate($period);

        $query = SalesOrderItem::select(
            'product_variants.brand_id',
            DB::raw('brands.name as brand_name'),
            DB::raw('SUM(sales_order_items.total) as revenue'),
            DB::raw('SUM(sales_order_items.unit_cost * sales_order_items.quantity) as total_cost'),
            DB::raw('SUM((sales_order_items.unit_price * (1 - sales_order_items.discount_percent / 100) - sales_order_items.unit_cost) * sales_order_items.quantity) as profit'),
            DB::raw('SUM(sales_order_items.quantity) as units_sold')
        )
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->join('product_variants', 'product_variants.id', '=', 'sales_order_items.variant_id')
            ->join('brands', 'brands.id', '=', 'product_variants.brand_id')
            ->where('sales_orders.created_at', '>=', $startDate)
            ->whereIn('sales_orders.status', ['confirmed', 'processing', 'completed']);

        if ($warehouseId) {
            $query->where('sales_order_items.warehouse_id', $warehouseId);
        }

        $results = $query->groupBy('product_variants.brand_id', 'brands.name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        return $results->map(function ($row) {
            $revenue = (float) $row->revenue;
            $profit = (float) $row->profit;

            return [
                'brand' => $row->brand_name,
                'revenue' => $revenue,
                'cost' => (float) $row->total_cost,
                'profit' => $profit,
                'margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0,
                'units_sold' => (int) $row->units_sold,
            ];
        })->toArray();
    }

    /**
     * Get sales distribution by product category.
     */
    public function getSalesByCategory(?int $warehouseId = null, ?string $period = '30d'): array
    {
        $startDate = $this->getPeriodStartDate($period);

        // Use DB::table for SQLite compatibility, LEFT JOIN for products without category
        $query = DB::table('sales_order_items')
            ->select(
                DB::raw("COALESCE(categories.name, 'Sin Categoría') as category_name"),
                DB::raw('COALESCE(SUM(sales_order_items.total), 0) as revenue'),
                DB::raw('COALESCE(SUM(sales_order_items.quantity), 0) as units_sold')
            )
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->join('products', 'products.id', '=', 'sales_order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales_orders.created_at', '>=', $startDate->toDateTimeString())
            ->whereIn('sales_orders.status', ['confirmed', 'processing', 'completed']);

        if ($warehouseId) {
            $query->where('sales_order_items.warehouse_id', $warehouseId);
        }

        $results = $query->groupBy(DB::raw("COALESCE(categories.name, 'Sin Categoría')"))
            ->orderByDesc('revenue')
            ->get();

        return $results->map(function ($row) {
            return [
                'category' => $row->category_name,
                'revenue' => (float) $row->revenue,
                'units_sold' => (int) $row->units_sold,
            ];
        })->toArray();
    }

    /**
     * Get ranking of top performing cashiers/users.
     */
    public function getTopCashiers(int $limit = 5, ?int $warehouseId = null, ?string $period = '30d'): array
    {
        $startDate = $this->getPeriodStartDate($period);

        $query = SalesOrder::select(
            'users.name as user_name',
            DB::raw('COUNT(sales_orders.id) as total_orders'),
            DB::raw('SUM(sales_orders.total) as total_revenue')
        )
            ->join('users', 'users.id', '=', 'sales_orders.user_id')
            ->where('sales_orders.created_at', '>=', $startDate)
            ->whereIn('sales_orders.status', ['confirmed', 'processing', 'completed']);

        if ($warehouseId) {
            $query->whereHas('items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        $results = $query->groupBy('users.id', 'users.name')
            ->orderByDesc('total_revenue')
            ->limit($limit)
            ->get();

        return $results->map(function ($row) {
            return [
                'name' => $row->user_name,
                'orders' => (int) $row->total_orders,
                'revenue' => (float) $row->total_revenue,
            ];
        })->toArray();
    }

    /**
     * Get recent system activity (sales and inventory movements).
     */
    public function getRecentActivity(int $limit = 10, ?int $warehouseId = null): array
    {
        // Get recent sales
        $salesQuery = SalesOrder::select(
            'id',
            'order_number as reference',
            'total as amount',
            'status',
            'created_at',
            DB::raw("'sale' as type")
        )
            ->whereIn('status', ['confirmed', 'completed']);

        if ($warehouseId) {
            $salesQuery->whereHas('items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        $sales = $salesQuery->orderByDesc('created_at')->limit($limit)->get();

        // Note: For a true activity feed, you might union SalesOrder and InventoryMovement
        // but for simplicity, returning recent sales orders is a good start as activity
        // Map to standard activity format
        return $sales->map(function ($sale) {
            return [
                'id' => $sale->id,
                'type' => 'sale',
                'description' => "Venta completada ({$sale->reference})",
                'amount' => (float) $sale->amount,
                'date' => $sale->created_at->toISOString(),
            ];
        })->toArray();
    }
}
