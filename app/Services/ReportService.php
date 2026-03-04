<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\InventoryMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Generate stock levels report
     */
    public function generateStockLevelsReport(array $filters = []): array
    {
        // Use defaultVariant.brand — Product has no direct 'brand' Eloquent relation
        $query = ProductInventory::with([
            'product.defaultVariant.brand',
            'product.defaultVariant.pricing',
            'warehouse',
        ])->select('product_inventory.*');

        // Apply filters
        if (!empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (!empty($filters['brand_id'])) {
            $query->whereHas('product.variants', function ($q) use ($filters) {
                $q->where('brand_id', $filters['brand_id']);
            });
        }
        
        if (!empty($filters['category_id'])) {
            $query->whereHas('product', function ($q) use ($filters) {
                $q->where('category_id', $filters['category_id']);
            });
        }

        // Only rows that have a product
        $query->whereHas('product');

        $inventory = $query->get();

        // Calculate metrics
        $summary = $this->calculateStockMetrics($inventory);

        // Group by warehouse
        $byWarehouse = $this->groupByWarehouse($inventory);

        // Filter by status if needed
        if (!empty($filters['status'])) {
            $inventory = $inventory->filter(function ($item) use ($filters) {
                return $this->getStockStatus($item) === $filters['status'];
            })->values();
        }

        // Format products
        $products = $inventory->map(function ($item) {
            $defaultVariant = $item->product->defaultVariant;
            $basePrice = $defaultVariant?->pricing?->base_price ?? 0;

            return [
                'id'            => $item->product_id,
                'sku'           => $item->product->sku_base ?? '',
                'name'          => $item->product->name,
                'brand'         => $defaultVariant?->brand?->name ?? 'Sin Marca',
                'warehouse_id'  => $item->warehouse_id,
                'warehouse'     => $item->warehouse?->name ?? '',
                'current_stock' => $item->quantity_available,
                'reorder_point' => $item->reorder_point ?? 0,
                'target_level'  => $item->reorder_quantity ?? 0,
                'status'        => $this->getStockStatus($item),
                'stock_value'   => round($item->quantity_available * $basePrice, 2),
            ];
        });

        return [
            'summary'      => $summary,
            'by_warehouse' => $byWarehouse,
            'products'     => $products->values()->all(),
        ];
    }

    /**
     * Generate movement history report
     */
    public function generateMovementHistoryReport(array $filters): array
    {
        $query = InventoryMovement::with(['product', 'warehouse', 'destinationWarehouse', 'user'])
            ->whereBetween('created_at', [$filters['date_from'], $filters['date_to']]);

        // Apply filters
        if (!empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (!empty($filters['warehouse_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('warehouse_id', $filters['warehouse_id'])
                  ->orWhere('destination_warehouse_id', $filters['warehouse_id']);
            });
        }

        if (!empty($filters['movement_type'])) {
            $query->where('movement_type', $filters['movement_type']);
        }
        
        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        $movements = $query->orderBy('created_at', 'desc')->get();

        // Calculate summary
        $summary = $this->calculateMovementSummary($movements);

        // Group by timeline
        $groupBy = $filters['group_by'] ?? 'day';
        $timeline = $this->groupMovementsByTimeline($movements, $groupBy);

        // Group by product
        $byProduct = $this->groupByProduct($movements);

        // Format movements
        $formattedMovements = $movements->take(100)->map(function ($movement) {
            return [
                'id' => $movement->id,
                'date' => $movement->created_at->format('Y-m-d H:i:s'),
                'product_id' => $movement->product_id,
                'product_name' => $movement->product->name,
                'product_sku' => $movement->product->sku,
                'warehouse' => $movement->warehouse->name,
                'destination_warehouse' => $movement->destinationWarehouse?->name,
                'movement_type' => $movement->movement_type,
                'quantity' => $movement->quantity,
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
                'user' => $movement->user?->name,
            ];
        });

        return [
            'summary' => $summary,
            'timeline' => $timeline,
            'by_product' => $byProduct,
            'movements' => $formattedMovements->all(),
        ];
    }

    /**
     * Calculate stock metrics
     */
    protected function calculateStockMetrics(Collection $inventory): array
    {
        $totalProducts = $inventory->count();
        $critical = 0;
        $low = 0;
        $normal = 0;
        $optimal = 0;
        $totalValue = 0;

        foreach ($inventory as $item) {
            $status = $this->getStockStatus($item);

            match ($status) {
                'critical' => $critical++,
                'low'      => $low++,
                'normal'   => $normal++,
                'optimal'  => $optimal++,
                default    => null,
            };

            $basePrice = $item->product?->defaultVariant?->pricing?->base_price ?? 0;
            $totalValue += $item->quantity_available * $basePrice;
        }

        return [
            'total_products' => $totalProducts,
            'critical_stock' => $critical,
            'low_stock'      => $low,
            'normal_stock'   => $normal,
            'optimal_stock'  => $optimal,
            'total_value'    => round($totalValue, 2),
        ];
    }

    /**
     * Get stock status
     */
    protected function getStockStatus($item): string
    {
        $stock = $item->quantity_available;
        $reorderPoint = $item->reorder_point ?? 0;
        $targetLevel = $item->reorder_quantity ?? 0;

        if ($stock <= 0) {
            return 'critical';
        }

        if ($reorderPoint > 0 && $stock <= $reorderPoint) {
            return 'critical';
        }

        if ($reorderPoint > 0 && $stock <= ($reorderPoint * 1.5)) {
            return 'low';
        }

        if ($targetLevel > 0 && $stock >= $targetLevel) {
            return 'optimal';
        }

        return 'normal';
    }

    /**
     * Group by warehouse
     */
    protected function groupByWarehouse(Collection $inventory): array
    {
        return $inventory->groupBy('warehouse_id')->map(function ($items, $warehouseId) {
            $warehouse = $items->first()->warehouse;
            $metrics = $this->calculateStockMetrics($items);

            return [
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $warehouse->name,
                'total_products' => $metrics['total_products'],
                'critical' => $metrics['critical_stock'],
                'low' => $metrics['low_stock'],
                'normal' => $metrics['normal_stock'],
                'optimal' => $metrics['optimal_stock'],
            ];
        })->values()->all();
    }

    /**
     * Calculate movement summary
     */
    protected function calculateMovementSummary(Collection $movements): array
    {
        $totalInbound = $movements->where('movement_type', 'in')->count();
        $totalOutbound = $movements->where('movement_type', 'out')->count();
        $totalTransfers = $movements->where('movement_type', 'transfer')->count();

        $quantityIn = $movements->where('movement_type', 'in')->sum('quantity');
        $quantityOut = $movements->where('movement_type', 'out')->sum('quantity');

        return [
            'total_movements' => $movements->count(),
            'total_inbound' => $totalInbound,
            'total_outbound' => $totalOutbound,
            'total_transfers' => $totalTransfers,
            'total_quantity_in' => $quantityIn,
            'total_quantity_out' => $quantityOut,
            'net_change' => $quantityIn - $quantityOut,
        ];
    }

    /**
     * Group movements by timeline
     */
    public function groupMovementsByTimeline(Collection $movements, string $groupBy): array
    {
        $format = match ($groupBy) {
            'week' => 'Y-W',
            'month' => 'Y-m',
            default => 'Y-m-d',
        };

        return $movements->groupBy(function ($movement) use ($format) {
            return $movement->created_at->format($format);
        })->map(function ($items, $date) {
            $inbound = $items->where('movement_type', 'in')->sum('quantity');
            $outbound = $items->where('movement_type', 'out')->sum('quantity');
            $transfers = $items->where('movement_type', 'transfer')->count();

            return [
                'date' => $date,
                'inbound' => $inbound,
                'outbound' => $outbound,
                'transfers' => $transfers,
                'net' => $inbound - $outbound,
            ];
        })->sortKeys()->values()->all();
    }

    /**
     * Group by product
     */
    protected function groupByProduct(Collection $movements): array
    {
        return $movements->groupBy('product_id')->map(function ($items, $productId) {
            $product = $items->first()->product;

            return [
                'product_id' => $productId,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'total_movements' => $items->count(),
                'inbound' => $items->where('movement_type', 'in')->count(),
                'outbound' => $items->where('movement_type', 'out')->count(),
                'transfers' => $items->where('movement_type', 'transfer')->count(),
            ];
        })->sortByDesc('total_movements')->take(10)->values()->all();
    }

    /**
     * Generate financial report (Revenue, COGS, Profit)
     * Filters by warehouse_id through sales_order_items.
     * Uses unit_cost from sales_order_items for COGS.
     * Includes refund totals from approved SalesReturns.
     */
    public function generateFinancialReport(array $filters): array
    {
        $warehouseId = $filters['warehouse_id'] ?? null;

        $query = \App\Models\SalesOrder::with(['items'])
            ->whereIn('status', ['confirmed', 'processing', 'completed'])
            ->whereBetween('date', [$filters['date_from'] . ' 00:00:00', $filters['date_to'] . ' 23:59:59']);

        // Filter orders that have items in the given warehouse
        if ($warehouseId) {
            $query->whereHas('items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        $orders = $query->orderBy('date', 'asc')->get();

        $totalRevenue = 0;
        $totalCogs = 0;
        $totalTax = 0;
        $totalDiscounts = 0;
        
        $timeline = [];

        foreach ($orders as $order) {
            $date = $order->date->format('Y-m-d');
            if (!isset($timeline[$date])) {
                $timeline[$date] = [
                    'date' => $date,
                    'revenue' => 0,
                    'cogs' => 0,
                    'profit' => 0,
                    'orders_count' => 0,
                ];
            }

            $orderCogs = 0;
            $orderDiscount = 0;

            $items = $warehouseId
                ? $order->items->where('warehouse_id', $warehouseId)
                : $order->items;

            foreach ($items as $item) {
                // Use unit_cost stored on the line item
                $cost = (float) $item->unit_cost;
                $orderCogs += ($cost * $item->quantity);
                $orderDiscount += (float) $item->discount_amount;
            }

            $totalCogs += $orderCogs;
            $totalTax += $order->tax_amount;
            $totalDiscounts += $orderDiscount;

            $timeline[$date]['cogs'] += $orderCogs;
            $timeline[$date]['orders_count'] += 1;
        }

        // Cash-basis: Fetch payments for the given period
        $paymentsQuery = \App\Models\SalesOrderPayment::whereBetween('paid_at', [$filters['date_from'] . ' 00:00:00', $filters['date_to'] . ' 23:59:59']);

        if ($warehouseId) {
            $paymentsQuery->whereHas('salesOrder.items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        $payments = $paymentsQuery->get();

        foreach ($payments as $payment) {
            $paymentDate = \Carbon\Carbon::parse($payment->paid_at)->format('Y-m-d');
            
            if (!isset($timeline[$paymentDate])) {
                $timeline[$paymentDate] = [
                    'date' => $paymentDate,
                    'revenue' => 0,
                    'cogs' => 0,
                    'profit' => 0,
                    'orders_count' => 0,
                ];
            }

            $timeline[$paymentDate]['revenue'] += $payment->amount;
            $totalRevenue += $payment->amount;
        }

        // Refunds: Approved SalesReturns in the date range
        $refundsQuery = \App\Models\SalesReturn::where('status', 'approved')
            ->whereBetween('created_at', [$filters['date_from'] . ' 00:00:00', $filters['date_to'] . ' 23:59:59']);

        if ($warehouseId) {
            $refundsQuery->where('warehouse_id', $warehouseId);
        }

        $totalRefunds = (float) $refundsQuery->sum('total_amount');

        // Recalculate profit per timeline day
        foreach ($timeline as &$dayData) {
            $dayData['profit'] = $dayData['revenue'] - $dayData['cogs'];
        }

        $netProfit = $totalRevenue - $totalCogs - $totalRefunds;
        $margin = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;

        return [
            'summary' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_cogs' => round($totalCogs, 2),
                'total_refunds' => round($totalRefunds, 2),
                'net_profit' => round($netProfit, 2),
                'margin_percentage' => round($margin, 2),
                'total_tax' => round($totalTax, 2),
                'total_discounts' => round($totalDiscounts, 2),
                'orders_count' => $orders->count(),
            ],
            'timeline' => array_values($timeline),
        ];
    }

    /**
     * Generate brand profitability report
     * Filters by warehouse_id through sales_order_items.
     * Uses unit_cost from sales_order_items for COGS.
     */
    public function generateBrandProfitability(array $filters): array
    {
        $warehouseId = $filters['warehouse_id'] ?? null;

        $query = \App\Models\SalesOrder::with(['items.product.defaultVariant.brand', 'items.variant.brand'])
            ->whereIn('status', ['confirmed', 'processing', 'completed'])
            ->whereBetween('date', [$filters['date_from'] . ' 00:00:00', $filters['date_to'] . ' 23:59:59']);

        if ($warehouseId) {
            $query->whereHas('items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        $orders = $query->get();

        $brandsData = [];

        foreach ($orders as $order) {
            $items = $warehouseId
                ? $order->items->where('warehouse_id', $warehouseId)
                : $order->items;

            foreach ($items as $item) {
                // Determine brand
                $brandId = null;
                $brandName = 'Sin Marca';

                if ($item->variant && $item->variant->brand) {
                    $brandId = $item->variant->brand->id;
                    $brandName = $item->variant->brand->name;
                } elseif ($item->product && $item->product->defaultVariant && $item->product->defaultVariant->brand) {
                    $brandId = $item->product->defaultVariant->brand->id;
                    $brandName = $item->product->defaultVariant->brand->name;
                }

                $key = $brandId ?? 'unknown';

                if (!isset($brandsData[$key])) {
                    $brandsData[$key] = [
                        'brand_id' => $brandId,
                        'brand_name' => $brandName,
                        'revenue' => 0,
                        'cogs' => 0,
                        'profit' => 0,
                        'items_sold' => 0,
                    ];
                }

                $revenue = $item->subtotal;
                $cogs = (float) $item->unit_cost * $item->quantity;

                $brandsData[$key]['revenue'] += $revenue;
                $brandsData[$key]['cogs'] += $cogs;
                $brandsData[$key]['profit'] += ($revenue - $cogs);
                $brandsData[$key]['items_sold'] += $item->quantity;
            }
        }

        // Calculate margins and sort by profit
        $result = collect(array_values($brandsData))->map(function ($data) {
            $data['margin'] = $data['revenue'] > 0 ? ($data['profit'] / $data['revenue']) * 100 : 0;
            $data['revenue'] = round($data['revenue'], 2);
            $data['cogs'] = round($data['cogs'], 2);
            $data['profit'] = round($data['profit'], 2);
            $data['margin'] = round($data['margin'], 2);
            return $data;
        })->sortByDesc('profit')->values()->all();

        return ['data' => $result];
    }

    /**
     * Generate category profitability report
     * Groups sold items by product.category.
     * Filters by warehouse_id through sales_order_items.
     * Uses unit_cost from sales_order_items for COGS.
     */
    public function generateCategoryProfitability(array $filters): array
    {
        $warehouseId = $filters['warehouse_id'] ?? null;

        $query = \App\Models\SalesOrder::with(['items.product.category'])
            ->whereIn('status', ['confirmed', 'processing', 'completed'])
            ->whereBetween('date', [$filters['date_from'] . ' 00:00:00', $filters['date_to'] . ' 23:59:59']);

        if ($warehouseId) {
            $query->whereHas('items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        $orders = $query->get();

        $categoriesData = [];

        foreach ($orders as $order) {
            $items = $warehouseId
                ? $order->items->where('warehouse_id', $warehouseId)
                : $order->items;

            foreach ($items as $item) {
                $categoryId = $item->product->category_id ?? null;
                $categoryName = $item->product->category->name ?? 'Sin Categoría';

                $key = $categoryId ?? 'unknown';

                if (!isset($categoriesData[$key])) {
                    $categoriesData[$key] = [
                        'category_id' => $categoryId,
                        'category_name' => $categoryName,
                        'revenue' => 0,
                        'cogs' => 0,
                        'profit' => 0,
                        'items_sold' => 0,
                    ];
                }

                $revenue = $item->subtotal;
                $cogs = (float) $item->unit_cost * $item->quantity;

                $categoriesData[$key]['revenue'] += $revenue;
                $categoriesData[$key]['cogs'] += $cogs;
                $categoriesData[$key]['profit'] += ($revenue - $cogs);
                $categoriesData[$key]['items_sold'] += $item->quantity;
            }
        }

        // Calculate margins and sort by profit
        $result = collect(array_values($categoriesData))->map(function ($data) {
            $data['margin'] = $data['revenue'] > 0 ? ($data['profit'] / $data['revenue']) * 100 : 0;
            $data['revenue'] = round($data['revenue'], 2);
            $data['cogs'] = round($data['cogs'], 2);
            $data['profit'] = round($data['profit'], 2);
            $data['margin'] = round($data['margin'], 2);
            return $data;
        })->sortByDesc('profit')->values()->all();

        return ['data' => $result];
    }

    /**
     * Generate Z-Report (Cierre de Caja / Arqueo)
     * Includes cash drawer reconciliation, real net sales, daily P&L, and average ticket.
     */
    public function generateZReport(string $date, ?int $warehouseId = null, ?int $userId = null): array
    {
        $startOfDay = \Carbon\Carbon::parse($date)->startOfDay();
        $endOfDay = \Carbon\Carbon::parse($date)->endOfDay();

        // =========================================
        // 1. CASH DRAWER: PosSession opening balance
        // =========================================
        $openingAmount = (float) \App\Models\PosSession::whereBetween('opened_at', [$startOfDay, $endOfDay])
            ->when($warehouseId, fn($q) => $q->where('warehouse_id', $warehouseId))
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->sum('opening_amount');

        // =========================================
        // 2. PAYMENTS received today (Cash-basis revenue)
        // =========================================
        $paymentsQuery = \App\Models\SalesOrderPayment::with('salesOrder')
            ->whereBetween('paid_at', [$startOfDay, $endOfDay]);
            
        if ($warehouseId || $userId) {
            $paymentsQuery->whereHas('salesOrder', function ($q) use ($warehouseId, $userId) {
                if ($userId) {
                    $q->where('user_id', $userId);
                }
                if ($warehouseId) {
                    $q->whereHas('items', function ($q2) use ($warehouseId) {
                        $q2->where('warehouse_id', $warehouseId);
                    });
                }
            });
        }
        
        $payments = $paymentsQuery->get();

        // =========================================
        // 3. ORDERS created today (for count, tax, discounts, COGS)
        // =========================================
        $ordersQuery = \App\Models\SalesOrder::with(['payments', 'items'])
            ->whereIn('status', ['confirmed', 'processing', 'completed'])
            ->whereBetween('created_at', [$startOfDay, $endOfDay]);
            
        if ($userId) {
            $ordersQuery->where('user_id', $userId);
        }
        
        if ($warehouseId) {
            $ordersQuery->whereHas('items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }
        
        $ordersToday = $ordersQuery->get();

        // =========================================
        // 4. REFUNDS: Approved SalesReturns for the day
        // =========================================
        $refundsQuery = \App\Models\SalesReturn::where('status', 'approved')
            ->whereBetween('created_at', [$startOfDay, $endOfDay]);

        if ($userId) {
            $refundsQuery->where('user_id', $userId);
        }

        if ($warehouseId) {
            $refundsQuery->where('warehouse_id', $warehouseId);
        }

        $totalRefunds = (float) $refundsQuery->sum('total_amount');

        // =========================================
        // 5. BUILD SUMMARY
        // =========================================
        $summary = [
            'date' => $date,
            'total_sales' => 0,          // Gross sales (from payments)
            'total_tax' => 0,
            'total_discounts' => 0,
            'total_shipping' => 0,
            'refunds' => round($totalRefunds, 2),
            'net_sales' => 0,            // total_sales - refunds
            'orders_count' => $ordersToday->count(),
            'average_ticket' => 0,
            'cogs' => 0,                 // Cost of goods sold today
            'gross_profit' => 0,         // net_sales - cogs
            'by_payment_method' => [],
            'cash_drawer' => [
                'opening_amount' => round($openingAmount, 2),
                'cash_sales' => 0,
                'cash_refunds' => 0,
                'expected_cash' => 0,
            ],
        ];

        // 5a. Payments → total_sales + by_payment_method
        foreach ($payments as $payment) {
            $summary['total_sales'] += $payment->amount;

            $method = $payment->method ?? 'unknown';
            if (!isset($summary['by_payment_method'][$method])) {
                $summary['by_payment_method'][$method] = 0;
            }
            $summary['by_payment_method'][$method] += $payment->amount;
        }

        // 5b. Orders today → tax, discounts, shipping, COGS, + legacy cash
        foreach ($ordersToday as $order) {
            $summary['total_tax'] += $order->tax_amount;
            $summary['total_discounts'] += $order->discount_amount;
            $summary['total_shipping'] += $order->shipping_cost;

            // COGS: sum unit_cost * quantity for each item
            $items = $warehouseId
                ? $order->items->where('warehouse_id', $warehouseId)
                : $order->items;

            foreach ($items as $item) {
                $summary['cogs'] += ((float) $item->unit_cost * $item->quantity);
            }

            // LEGACY: If order is paid but has no payment records
            if ($order->payment_status === 'paid' && $order->payments->isEmpty()) {
                $summary['total_sales'] += $order->total;
                $method = $order->payment_method ?? 'cash';
                if (!isset($summary['by_payment_method'][$method])) {
                    $summary['by_payment_method'][$method] = 0;
                }
                $summary['by_payment_method'][$method] += $order->total;
            }
        }

        // 5c. Calculate derived metrics
        $summary['net_sales'] = $summary['total_sales'] - $totalRefunds;
        $summary['gross_profit'] = $summary['net_sales'] - $summary['cogs'];
        $summary['average_ticket'] = $summary['orders_count'] > 0
            ? $summary['net_sales'] / $summary['orders_count']
            : 0;

        // 5d. Cash drawer reconciliation
        $cashSales = $summary['by_payment_method']['cash'] ?? 0;
        // For simplicity, assume all refunds could be in cash; 
        // if a refund_method field exists, this should be filtered.
        $cashRefunds = $totalRefunds;

        // Cash movements (retiros / ingresos extra) from sessions of the day
        $sessionIds = \App\Models\PosSession::whereBetween('opened_at', [$startOfDay, $endOfDay])
            ->when($warehouseId, fn($q) => $q->where('warehouse_id', $warehouseId))
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->pluck('id');

        $cashMovementsIn  = (float) \App\Models\CashMovement::whereIn('pos_session_id', $sessionIds)->where('type', 'in')->sum('amount');
        $cashMovementsOut = (float) \App\Models\CashMovement::whereIn('pos_session_id', $sessionIds)->where('type', 'out')->sum('amount');

        $summary['cash_drawer']['cash_sales'] = round($cashSales, 2);
        $summary['cash_drawer']['cash_refunds'] = round($cashRefunds, 2);
        $summary['cash_drawer']['cash_movements_in'] = round($cashMovementsIn, 2);
        $summary['cash_drawer']['cash_movements_out'] = round($cashMovementsOut, 2);
        $summary['cash_drawer']['expected_cash'] = round($openingAmount + $cashSales - $cashRefunds + $cashMovementsIn - $cashMovementsOut, 2);

        // Round everything
        $summary['total_sales'] = round($summary['total_sales'], 2);
        $summary['total_tax'] = round($summary['total_tax'], 2);
        $summary['total_discounts'] = round($summary['total_discounts'], 2);
        $summary['total_shipping'] = round($summary['total_shipping'], 2);
        $summary['net_sales'] = round($summary['net_sales'], 2);
        $summary['average_ticket'] = round($summary['average_ticket'], 2);
        $summary['cogs'] = round($summary['cogs'], 2);
        $summary['gross_profit'] = round($summary['gross_profit'], 2);

        foreach ($summary['by_payment_method'] as $k => $v) {
            $summary['by_payment_method'][$k] = round($v, 2);
        }

        return $summary;
    }
}
