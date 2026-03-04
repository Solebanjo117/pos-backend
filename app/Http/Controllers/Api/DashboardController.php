<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService
    ) {}

    /**
     * Get dashboard statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $warehouseId = $request->input('warehouse_id');
        $stats = $this->dashboardService->getStats($warehouseId);

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Get top selling products.
     */
    public function topProducts(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 5);
        $warehouseId = $request->input('warehouse_id');
        $period = $request->input('period', '30d');
        
        $products = $this->dashboardService->getTopProducts($limit, $warehouseId, $period);

        return response()->json([
            'data' => $products,
        ]);
    }

    /**
     * Get products with low stock.
     */
    public function lowStockProducts(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);
        $warehouseId = $request->input('warehouse_id');
        
        $products = $this->dashboardService->getLowStockProducts($limit, $warehouseId);

        return response()->json([
            'data' => $products,
        ]);
    }

    /**
     * Get inventory summary by warehouse.
     */
    public function inventorySummary(Request $request): JsonResponse
    {
        $warehouseId = $request->input('warehouse_id');
        $summary = $this->dashboardService->getInventorySummary($warehouseId);

        return response()->json([
            'data' => $summary,
        ]);
    }

    /**
     * Get products that need reordering.
     */
    public function reorderAlerts(Request $request): JsonResponse
    {
        $warehouseId = $request->input('warehouse_id');
        $alerts = $this->dashboardService->getReorderAlerts($warehouseId);

        return response()->json([
            'data' => $alerts,
        ]);
    }

    /**
     * Get sales chart data.
     */
    public function salesChart(Request $request): JsonResponse
    {
        $period = $request->input('period', '7d');
        $warehouseId = $request->input('warehouse_id');
        
        $chartData = $this->dashboardService->getSalesChartData($period, $warehouseId);

        return response()->json([
            'data' => $chartData,
        ]);
    }

    /**
     * Get profitability by brand.
     */
    public function profitByBrand(Request $request): JsonResponse
    {
        $warehouseId = $request->input('warehouse_id');
        $period = $request->input('period', '30d');

        $data = $this->dashboardService->getProfitByBrand($warehouseId, $period);

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Get all dashboard data in a single request.
     */
    public function index(Request $request): JsonResponse
    {
        $warehouseId = $request->input('warehouse_id');
        $period = $request->input('period', '7d');
        
        return response()->json([
            'data' => [
                'stats' => $this->dashboardService->getStats($warehouseId),
                'top_products' => $this->dashboardService->getTopProducts(5, $warehouseId, $period),
                'low_stock_products' => $this->dashboardService->getLowStockProducts(5, $warehouseId),
                'inventory_summary' => $this->dashboardService->getInventorySummary($warehouseId),
                'sales_chart' => $this->dashboardService->getSalesChartData($period, $warehouseId),
                'profit_by_brand' => $this->dashboardService->getProfitByBrand($warehouseId, $period),
                'sales_by_category' => $this->dashboardService->getSalesByCategory($warehouseId, $period),
                'top_cashiers' => $this->dashboardService->getTopCashiers(5, $warehouseId, $period),
                'recent_activity' => $this->dashboardService->getRecentActivity(10, $warehouseId),
            ],
        ]);
    }
}
